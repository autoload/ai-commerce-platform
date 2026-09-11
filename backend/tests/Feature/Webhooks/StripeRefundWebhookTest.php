<?php

namespace Tests\Feature\Webhooks;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\RefundNotEligibleException;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\CheckoutOrderCreationService;
use App\Services\InventoryAdjustmentService;
use App\Services\OrderStatusUpdateService;
use App\Services\PaymentExpirySweepService;
use App\Services\RefundService;
use App\Services\StripePaymentIntentGateway;
use App\Services\StripeRefundGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Stripe\WebhookSignature;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\Doubles\FakePaymentIntentGateway;
use Tests\Doubles\FakeRefundGateway;
use Tests\TestCase;

/**
 * Phase 9D — refund.created / refund.updated webhook handling, added to
 * StripePaymentWebhookService. Mirrors StripeWebhookTest's discipline
 * exactly: exercises the real Stripe\Webhook::constructEvent()/
 * WebhookSignature verification path end to end (no fake — signature
 * verification is pure local HMAC, no network call), against a fixed
 * test webhook secret. Every fixture Order/Payment is produced by the
 * real CheckoutOrderCreationService (unmodified), so the inventory ledger
 * rows the restoration logic depends on are genuine, not hand-built.
 */
class StripeRefundWebhookTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret_for_phase_9d';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /**
     * @param  array<int, array{stock: int, price: float, quantity: int}>  $items
     * @return array{order: Order, payment: Payment, variants: array<int, ProductVariant>, stripePaymentIntentId: string}
     */
    private function paidFixture(array $items): array
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $lineItems = [];
        $variants = [];

        foreach ($items as $spec) {
            $product = Product::factory()->forStore($store)->create();
            $variant = ProductVariant::factory()->forProduct($product)->create([
                'price' => $spec['price'],
                'status' => CatalogStatus::Active,
            ]);

            app(InventoryAdjustmentService::class)->adjust(
                $variant, $spec['stock'], InventoryTransactionReason::Restock, null, null
            );

            $lineItems[] = ['variant' => $variant, 'quantity' => $spec['quantity']];
            $variants[] = $variant;
        }

        $stripePaymentIntentId = 'pi_test_'.Str::random(24);

        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            $lineItems,
            [
                'recipient_name' => 'Jane Doe',
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
            $stripePaymentIntentId,
        );

        $payment = $order->payments->first();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();

        $order->status = OrderStatus::Paid;
        $order->paid_at = now();
        $order->save();

        return [
            'order' => $order->fresh(),
            'payment' => $payment->fresh(),
            'variants' => $variants,
            'stripePaymentIntentId' => $stripePaymentIntentId,
        ];
    }

    /**
     * @param  array<string, mixed>  $objectExtra
     * @return array<string, mixed>
     */
    private function buildRefundEventPayload(
        string $eventId,
        string $type,
        string $paymentIntentId,
        string $refundId,
        string $status,
        int $amountCents,
        array $objectExtra = [],
    ): array {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => now()->timestamp,
            'data' => [
                'object' => array_merge([
                    'id' => $refundId,
                    'object' => 'refund',
                    'payment_intent' => $paymentIntentId,
                    'charge' => 'ch_test_'.Str::random(16),
                    'amount' => $amountCents,
                    'currency' => 'usd',
                    'status' => $status,
                    'reason' => null,
                ], $objectExtra),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function postWebhook(array $eventPayload): TestResponse
    {
        $body = json_encode($eventPayload, JSON_THROW_ON_ERROR);
        $signature = WebhookSignature::generateSignatureHeader($body, self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $body);
    }

    // -----------------------------------------------------------------
    // Core reconciliation
    // -----------------------------------------------------------------

    public function test_refund_created_with_succeeded_transitions_order_and_restores_inventory(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 3]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $payload = $this->buildRefundEventPayload(
            'evt_refund_created_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 6000
        );

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'succeeded']);
        $this->assertSame(OrderStatus::Refunded, $fixture['order']->fresh()->status);
        // 10 restocked, -3 claimed at checkout (7 on hand), +3 restored = 10
        $this->assertSame(10, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'reason' => 'refund',
            'delta' => 3,
        ]);
    }

    public function test_refund_created_with_pending_does_not_touch_order_or_inventory(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 3]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $payload = $this->buildRefundEventPayload(
            'evt_refund_created_2', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'pending', 6000
        );

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'pending']);
        $this->assertSame(OrderStatus::Paid, $fixture['order']->fresh()->status);
        $this->assertSame(7, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
    }

    public function test_refund_updated_transitions_pending_to_succeeded(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $refundId = 're_test_'.Str::random(16);

        $this->postWebhook($this->buildRefundEventPayload(
            'evt_ru_created', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'pending', 4000
        ))->assertOk();

        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_ru_updated', 'refund.updated', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ));

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'succeeded']);
        $this->assertSame(OrderStatus::Refunded, $fixture['order']->fresh()->status);
    }

    public function test_refund_updated_to_failed_leaves_order_and_inventory_untouched(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $this->postWebhook($this->buildRefundEventPayload(
            'evt_rf_created', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'pending', 4000
        ))->assertOk();

        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_rf_updated', 'refund.updated', $fixture['stripePaymentIntentId'], $refundId, 'failed', 4000
        ));

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'failed']);
        $this->assertSame(OrderStatus::Paid, $fixture['order']->fresh()->status);
        $this->assertSame(8, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
    }

    // -----------------------------------------------------------------
    // Stripe status mapping
    // -----------------------------------------------------------------

    public function test_requires_action_maps_to_local_pending(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 1]]);
        $refundId = 're_test_'.Str::random(16);

        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_ra_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'requires_action', 2000
        ));

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'pending']);
        $this->assertSame(OrderStatus::Paid, $fixture['order']->fresh()->status);
    }

    public function test_canceled_maps_to_local_failed(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 1]]);
        $refundId = 're_test_'.Str::random(16);

        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_canceled_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'canceled', 2000
        ));

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'failed']);
        $this->assertSame(OrderStatus::Paid, $fixture['order']->fresh()->status);
    }

    public function test_unrecognized_stripe_status_is_a_logged_no_op(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 1]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        // A hypothetical future Stripe status this local enum has no
        // mapping for — must not crash (no bare RefundStatus::from()) and
        // must not guess.
        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_unknown_status_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'some_future_status', 2000
        ));

        $response->assertOk();
        // Refund row is still created (find-or-create happens before the
        // status match), but left at its inserted default (pending) since
        // the unrecognized status never overwrites it.
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'pending']);
        $this->assertSame(OrderStatus::Paid, $fixture['order']->fresh()->status);
        $this->assertSame(9, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
    }

    // -----------------------------------------------------------------
    // Idempotency / terminal guard
    // -----------------------------------------------------------------

    public function test_duplicate_webhook_event_is_processed_only_once(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $payload = $this->buildRefundEventPayload(
            'evt_dup_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        );

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk(); // same event id — duplicate, safely ignored

        // stock 10, 2 claimed at checkout (8 on hand), 2 restored once (10) — not twice (12).
        $this->assertSame(10, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertDatabaseCount('inventory_transactions', 3); // 1 restock (seeding) + 1 checkout + 1 refund, not 4
    }

    public function test_a_different_event_id_for_an_already_terminal_refund_is_a_no_op(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $this->postWebhook($this->buildRefundEventPayload(
            'evt_terminal_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ))->assertOk();

        // A different Stripe event id, redelivering the same already-
        // succeeded refund — must not restore inventory or re-transition
        // the order a second time.
        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_terminal_2', 'refund.updated', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ));

        $response->assertOk();
        $this->assertSame(OrderStatus::Refunded, $fixture['order']->fresh()->status);
        // stock 10, 2 claimed then restored once = 10, unaffected by the second, redundant event.
        $this->assertSame(10, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertDatabaseCount('inventory_transactions', 3); // still 1 restock + 1 checkout + 1 refund
    }

    // -----------------------------------------------------------------
    // Dashboard-initiated refund / unknown PaymentIntent
    // -----------------------------------------------------------------

    public function test_dashboard_initiated_refund_is_created_from_the_webhook_directly(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 1]]);
        $refundId = 're_test_'.Str::random(16);

        // No prior local Refund row — simulates a refund issued directly
        // in the Stripe Dashboard, never via this app's merchant endpoint.
        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_dashboard_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 2000
        ));

        $response->assertOk();
        $this->assertDatabaseHas('refunds', [
            'stripe_refund_id' => $refundId,
            'status' => 'succeeded',
            'initiated_by_user_id' => null,
        ]);
        $this->assertSame(OrderStatus::Refunded, $fixture['order']->fresh()->status);
    }

    public function test_unknown_payment_intent_returns_404_and_rolls_back(): void
    {
        $payload = $this->buildRefundEventPayload(
            'evt_unknown_pi_1', 'refund.created', 'pi_does_not_exist_locally', 're_test_'.Str::random(16), 'succeeded', 1000
        );

        $response = $this->postWebhook($payload);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('stripe_webhook_events', ['stripe_event_id' => 'evt_unknown_pi_1']);
        $this->assertDatabaseCount('refunds', 0);
    }

    // -----------------------------------------------------------------
    // Atomicity: inventory restoration failure rolls back everything
    // -----------------------------------------------------------------

    public function test_webhook_transaction_rolls_back_entirely_when_inventory_restoration_fails_mid_loop(): void
    {
        $fixture = $this->paidFixture([
            ['stock' => 10, 'price' => 10.00, 'quantity' => 2],
            ['stock' => 10, 'price' => 10.00, 'quantity' => 2],
        ]);
        $variantOne = $fixture['variants'][0];
        $variantTwo = $fixture['variants'][1];
        $refundId = 're_test_'.Str::random(16);

        $beforeInventoryOne = Inventory::where('product_variant_id', $variantOne->id)->first()->quantity_on_hand;
        $beforeInventoryTwo = Inventory::where('product_variant_id', $variantTwo->id)->first()->quantity_on_hand;
        $beforeLedgerCount = InventoryTransaction::count();

        // Force a failure on the SECOND item's ledger insert only — the
        // first item's inventory update (already applied earlier in the
        // same loop, same transaction) must roll back too, proving the
        // restoration loop is not independently committed per item.
        $callCount = 0;
        InventoryTransaction::saving(function (InventoryTransaction $transaction) use (&$callCount) {
            if ($transaction->reason?->value === 'refund') {
                $callCount++;
                if ($callCount === 2) {
                    throw new RuntimeException('Forced failure on the second inventory restoration.');
                }
            }
        });

        try {
            $payload = $this->buildRefundEventPayload(
                'evt_forced_failure_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
            );

            $this->postWebhook($payload);
        } finally {
            InventoryTransaction::flushEventListeners();
        }

        // Everything from this delivery is gone: no webhook dedup row, no
        // Refund row, order left as it was, and BOTH variants' inventory
        // (including the first item, which "succeeded" before the forced
        // failure on the second) are exactly as they were before.
        $this->assertDatabaseMissing('stripe_webhook_events', ['stripe_event_id' => 'evt_forced_failure_1']);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(OrderStatus::Paid, $fixture['order']->fresh()->status);
        $this->assertSame($beforeInventoryOne, Inventory::where('product_variant_id', $variantOne->id)->first()->quantity_on_hand);
        $this->assertSame($beforeInventoryTwo, Inventory::where('product_variant_id', $variantTwo->id)->first()->quantity_on_hand);
        $this->assertSame($beforeLedgerCount, InventoryTransaction::count());
    }

    public function test_inventory_is_restored_exactly_once_across_every_line_item(): void
    {
        $fixture = $this->paidFixture([
            ['stock' => 10, 'price' => 10.00, 'quantity' => 2],
            ['stock' => 10, 'price' => 10.00, 'quantity' => 3],
        ]);
        $variantOne = $fixture['variants'][0];
        $variantTwo = $fixture['variants'][1];
        $refundId = 're_test_'.Str::random(16);

        $payload = $this->buildRefundEventPayload(
            'evt_full_restore_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 5000
        );

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $this->assertSame(10, Inventory::where('product_variant_id', $variantOne->id)->first()->quantity_on_hand);
        $this->assertSame(10, Inventory::where('product_variant_id', $variantTwo->id)->first()->quantity_on_hand);
        // 2 restock (fixture seeding) + 2 checkout (claim) + 2 refund (restoration) = 6.
        $this->assertDatabaseCount('inventory_transactions', 6);
        $this->assertSame(OrderStatus::Refunded, $fixture['order']->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Phase 9E-2 (G3-B) — manual compensation refund
    // -----------------------------------------------------------------

    private function fakeRefundGateway(): FakeRefundGateway
    {
        $fake = new FakeRefundGateway;
        $this->app->instance(StripeRefundGateway::class, $fake);

        return $fake;
    }

    /**
     * Builds a G3-B-eligible Order: a real checkout (real Checkout-reason
     * claims via the unmodified CheckoutOrderCreationService), then
     * simulates the cancel-then-late-succeed sequence directly (isolating
     * the refund-webhook behavior under test from the sweep/merchant-
     * cancel/G3-A webhook flows, each already covered by their own test
     * suites) -- the same "isolate the transition under test" discipline
     * StripeWebhookTest's own G3-A tests already establish. When
     * $releaseInventory is true (the realistic case -- see
     * test_g3b_end_to_end... below for the fully-real chain proving this
     * is what the sweep/webhook flow actually produces), a Release row is
     * inserted for every claim before the Order is marked Cancelled,
     * exactly as StripePaymentWebhookService::releaseInventoryForPayment()
     * / PaymentExpirySweepService would.
     *
     * @param  array<int, array{stock: int, price: float, quantity: int}>  $items
     * @return array{order: Order, payment: Payment, variants: array<int, ProductVariant>, stripePaymentIntentId: string}
     */
    private function cancelledAfterClosureFixture(array $items, bool $releaseInventory = true): array
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $lineItems = [];
        $variants = [];

        foreach ($items as $spec) {
            $product = Product::factory()->forStore($store)->create();
            $variant = ProductVariant::factory()->forProduct($product)->create([
                'price' => $spec['price'],
                'status' => CatalogStatus::Active,
            ]);

            app(InventoryAdjustmentService::class)->adjust(
                $variant, $spec['stock'], InventoryTransactionReason::Restock, null, null
            );

            $lineItems[] = ['variant' => $variant, 'quantity' => $spec['quantity']];
            $variants[] = $variant;
        }

        $stripePaymentIntentId = 'pi_test_'.Str::random(24);

        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            $lineItems,
            [
                'recipient_name' => 'Jane Doe',
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
            $stripePaymentIntentId,
        );

        $payment = $order->payments->first();

        if ($releaseInventory) {
            foreach ($order->items as $item) {
                app(InventoryAdjustmentService::class)->adjust(
                    $item->variant, $item->quantity, InventoryTransactionReason::Release, null, null, $item, $payment
                );
            }
        }

        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'expired';
        $order->cancelled_at = now();
        $order->save();

        // The late webhook succeeding -- Payment transitions, G3-A sets
        // the alarm (mirrors StripePaymentWebhookService's own real
        // recordPaymentSucceededAfterClosure() behavior, constructed
        // directly here since that transition is StripeWebhookTest's own
        // scope, not this file's).
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();

        $order->status_reason = 'payment_succeeded_after_closure';
        $order->save();

        return [
            'order' => $order->fresh(),
            'payment' => $payment->fresh(),
            'variants' => $variants,
            'stripePaymentIntentId' => $stripePaymentIntentId,
        ];
    }

    public function test_g3b_refund_does_not_double_credit_already_released_inventory(): void
    {
        $fixture = $this->cancelledAfterClosureFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $onHandBefore = Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand;
        $ledgerCountBefore = InventoryTransaction::count();

        $payload = $this->buildRefundEventPayload(
            'evt_g3b_double_credit_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        );

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'succeeded']);

        // Inventory was already fully returned by the prior Release -- the
        // refund must NOT credit it a second time.
        $this->assertSame($onHandBefore, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertSame($ledgerCountBefore, InventoryTransaction::count());
        $this->assertDatabaseMissing('inventory_transactions', [
            'payment_id' => $fixture['payment']->id,
            'reason' => 'refund',
        ]);

        // Order stays Cancelled; status_reason resolves to the "handled" value.
        $order = $fixture['order']->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('payment_refunded_after_closure', $order->status_reason);
    }

    /**
     * A constructed edge case (not the realistic every-time path -- see
     * the double-credit test above for that) proving the guard's fallback
     * branch: a G3-B-eligible order whose inventory was NOT actually
     * released still gets normal restoration, exactly as any other
     * refund would.
     */
    public function test_g3b_refund_restores_inventory_normally_when_no_prior_release_exists(): void
    {
        $fixture = $this->cancelledAfterClosureFixture(
            [['stock' => 10, 'price' => 20.00, 'quantity' => 2]],
            releaseInventory: false,
        );
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $payload = $this->buildRefundEventPayload(
            'evt_g3b_no_release_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        );

        $response = $this->postWebhook($payload);

        $response->assertOk();
        // 10 restocked, -2 claimed at checkout (8 on hand), +2 restored (back to 10).
        $this->assertSame(10, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'payment_id' => $fixture['payment']->id,
            'reason' => 'refund',
            'delta' => 2,
        ]);
    }

    /**
     * A deliberately mixed state -- only one of two line items' claims was
     * released -- proving the guard is evaluated per order_item_id, not
     * once for the whole payment.
     */
    public function test_g3b_refund_handles_mixed_released_and_unreleased_line_items_independently(): void
    {
        $fixture = $this->cancelledAfterClosureFixture([
            ['stock' => 10, 'price' => 10.00, 'quantity' => 2],
            ['stock' => 10, 'price' => 10.00, 'quantity' => 3],
        ], releaseInventory: false);

        $order = $fixture['order'];
        $payment = $fixture['payment'];
        $variantOne = $fixture['variants'][0];
        $variantTwo = $fixture['variants'][1];

        $firstItem = $order->items()->where('product_variant_id', $variantOne->id)->first();
        app(InventoryAdjustmentService::class)->adjust(
            $firstItem->variant, $firstItem->quantity, InventoryTransactionReason::Release, null, null, $firstItem, $payment
        );

        $refundId = 're_test_'.Str::random(16);
        $payload = $this->buildRefundEventPayload(
            'evt_g3b_mixed_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 5000
        );

        $response = $this->postWebhook($payload);

        $response->assertOk();
        // Variant one: already released -- stays at the released level, no double credit.
        $this->assertSame(10, Inventory::where('product_variant_id', $variantOne->id)->first()->quantity_on_hand);
        // Variant two: never released -- normal refund restoration fires (7 claimed -> 10 restored).
        $this->assertSame(10, Inventory::where('product_variant_id', $variantTwo->id)->first()->quantity_on_hand);

        $this->assertDatabaseMissing('inventory_transactions', [
            'product_variant_id' => $variantOne->id,
            'payment_id' => $payment->id,
            'reason' => 'refund',
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variantTwo->id,
            'payment_id' => $payment->id,
            'reason' => 'refund',
            'delta' => 3,
        ]);
    }

    public function test_g3b_duplicate_refund_webhook_does_not_double_credit_or_re_resolve(): void
    {
        $fixture = $this->cancelledAfterClosureFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $this->postWebhook($this->buildRefundEventPayload(
            'evt_g3b_dup_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ))->assertOk();

        $onHandAfterFirst = Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand;
        $ledgerCountAfterFirst = InventoryTransaction::count();

        // A different event id redelivering the same already-succeeded refund.
        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_g3b_dup_2', 'refund.updated', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ));

        $response->assertOk();
        $this->assertSame($onHandAfterFirst, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertSame($ledgerCountAfterFirst, InventoryTransaction::count());
        $this->assertSame('payment_refunded_after_closure', $fixture['order']->fresh()->status_reason);
    }

    public function test_normal_refund_does_not_set_g3b_resolved_status_reason(): void
    {
        $fixture = $this->paidFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $refundId = 're_test_'.Str::random(16);

        $this->postWebhook($this->buildRefundEventPayload(
            'evt_normal_g3b_check_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ))->assertOk();

        $order = $fixture['order']->fresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertNull($order->status_reason);
    }

    /**
     * The full realistic chain, end to end, with no constructed
     * intermediate state: real checkout -> real merchant cancellation of
     * the still-pending order (via OrderStatusUpdateService, the same
     * service OrderController uses) -> a real late payment_intent.succeeded
     * webhook (G3-A fires) -> a real merchant-initiated refund through
     * RefundService (the same code path RefundController calls) -> a real
     * refund.updated succeeded webhook (G3-B resolves).
     *
     * Deliberately uses merchant cancellation, not the expiry sweep, as
     * the realistic trigger -- confirmed by direct inspection (and by the
     * documentation test immediately below) that OrderStatusUpdateService
     * does NOT touch Payment or inventory when cancelling a Pending order,
     * so the Payment is still non-terminal when the late webhook arrives
     * and G3-A's handleSucceeded() guard lets it through; inventory was
     * never released, so the refund's normal (non-double-credit) Refund
     * restoration path is what correctly returns it. See the finding
     * documented on the next test for why the expiry-sweep path does NOT
     * currently reach G3-A at all.
     */
    public function test_g3b_end_to_end_merchant_cancellation_late_payment_and_manual_refund(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => 20.00,
            'status' => CatalogStatus::Active,
        ]);

        app(InventoryAdjustmentService::class)->adjust(
            $variant, 10, InventoryTransactionReason::Restock, null, null
        );

        $stripePaymentIntentId = 'pi_test_'.Str::random(24);

        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            [['variant' => $variant, 'quantity' => 2]],
            [
                'recipient_name' => 'Jane Doe',
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
            $stripePaymentIntentId,
        );

        $payment = $order->payments->first();
        $this->assertSame(8, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);

        // Real merchant cancellation of the still-pending order.
        app(OrderStatusUpdateService::class)->transition($order, OrderStatus::Cancelled);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNull($order->status_reason);
        // Confirmed: merchant cancellation does not release inventory.
        $this->assertSame(8, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);

        // The residual race: Stripe's real PaymentIntent succeeds after
        // the order was already cancelled -- a real
        // payment_intent.succeeded webhook arrives. Payment is still
        // non-terminal (RequiresPayment), so handleSucceeded() proceeds.
        $succeededPayload = [
            'id' => 'evt_g3b_e2e_succeeded',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'created' => now()->timestamp,
            'data' => [
                'object' => [
                    'id' => $stripePaymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => 4000,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                ],
            ],
        ];
        $this->postWebhook($succeededPayload)->assertOk();

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status); // never reopened
        $this->assertSame('payment_succeeded_after_closure', $order->status_reason);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        // Merchant-initiated G3-B compensation refund, through the real
        // RefundService -- the same code path RefundController calls.
        $this->fakeRefundGateway();
        $result = app(RefundService::class)->refund($order, 'Late payment after cancellation', 'g3b-e2e-key', null);
        $refund = $result['refund'];
        $this->assertSame('pending', $refund->status->value);

        // The resulting refund.updated webhook -- resolves status_reason,
        // and restores inventory normally (no prior Release exists for
        // this claim, since merchant cancellation never released it).
        $refundSucceededPayload = $this->buildRefundEventPayload(
            'evt_g3b_e2e_refund_succeeded', 'refund.updated', $stripePaymentIntentId, $refund->stripe_refund_id, 'succeeded', 4000
        );
        $this->postWebhook($refundSucceededPayload)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status); // still never reopened
        $this->assertSame('payment_refunded_after_closure', $order->status_reason);
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'succeeded']);
        $this->assertSame(10, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'payment_id' => $payment->id,
            'reason' => 'refund',
            'delta' => 2,
        ]);
    }

    /**
     * Phase 9E-3 -- the full realistic chain, end to end: real checkout
     * -> real expiry sweep (Payment Canceled/'expired', Order
     * Cancelled/'expired', inventory genuinely released) -> a real late
     * payment_intent.succeeded webhook -> 9E-3 detection.
     *
     * This scenario was previously undetected (see the G3-B implementation
     * notes: PaymentExpirySweepService marks Payment Canceled in the same
     * transaction that releases inventory and cancels the Order, so
     * handleSucceeded()'s pre-existing terminal-status guard silently
     * discarded a genuinely later payment_intent.succeeded before G3-A's
     * own alarm logic could ever run). Phase 9E-3 closes that gap with a
     * narrow, separate detection branch -- this test proves the fix holds
     * across the entire real chain, not just the webhook step in
     * isolation, and that the terminal Payment invariant, inventory, and
     * G3-B eligibility are all left exactly as the approved design
     * requires.
     */
    public function test_9e3_end_to_end_expiry_sweep_late_payment_detection(): void
    {
        config(['services.stripe.checkout_expiry_minutes' => 30]);
        $this->app->instance(StripePaymentIntentGateway::class, new FakePaymentIntentGateway);

        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => 20.00,
            'status' => CatalogStatus::Active,
        ]);

        app(InventoryAdjustmentService::class)->adjust(
            $variant, 10, InventoryTransactionReason::Restock, null, null
        );

        $stripePaymentIntentId = 'pi_test_'.Str::random(24);

        $this->travelTo(now()->subMinutes(35));
        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            [['variant' => $variant, 'quantity' => 2]],
            [
                'recipient_name' => 'Jane Doe',
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
            $stripePaymentIntentId,
        );
        $this->travelBack();

        $payment = $order->payments->first();

        // Real expiry sweep -- cancels the order, releases inventory.
        $counts = app(PaymentExpirySweepService::class)->sweep();
        $this->assertSame(1, $counts['cancelled']);

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('expired', $order->status_reason);
        $this->assertSame(PaymentStatus::Canceled, $payment->status);
        $this->assertSame('expired', $payment->failure_reason);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 10]);

        $ledgerCountBeforeWebhook = InventoryTransaction::count();

        // The residual race: Stripe's real PaymentIntent actually
        // succeeds after the sweep already canceled it locally.
        $succeededPayload = [
            'id' => 'evt_9e3_e2e_succeeded',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'created' => now()->timestamp,
            'data' => [
                'object' => [
                    'id' => $stripePaymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => 4000,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                ],
            ],
        ];
        $this->postWebhook($succeededPayload)->assertOk();

        $order->refresh();
        $payment->refresh();

        // Terminal Payment invariant preserved.
        $this->assertSame(PaymentStatus::Canceled, $payment->status);
        // Order never reopened.
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('payment_succeeded_after_expiry_cancellation', $order->status_reason);

        // No inventory mutation of any kind.
        $this->assertSame($ledgerCountBeforeWebhook, InventoryTransaction::count());
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 10]);

        // No Refund, no money movement.
        $this->assertDatabaseCount('refunds', 0);

        // Durable webhook evidence.
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_9e3_e2e_succeeded',
            'type' => 'payment_intent.succeeded',
        ]);

        // G3-B remains ineligible: no Succeeded Payment exists for this
        // order, and its status_reason is the distinct 9E-3 value, not
        // G3-A's payment_succeeded_after_closure.
        $this->fakeRefundGateway();
        $this->expectException(RefundNotEligibleException::class);
        app(RefundService::class)->refund($order, null, '9e3-e2e-key', null);
    }

    // -----------------------------------------------------------------
    // Expiry-sweep late-success compensation refund
    // -----------------------------------------------------------------

    /**
     * Builds an expiry-sweep-late-success-eligible Order the same isolated
     * way cancelledAfterClosureFixture() does for the G3-B case above —
     * real checkout claims, then the sweep-then-late-succeed sequence
     * constructed directly (that transition is StripeWebhookTest's own
     * scope, and is exercised end to end, not constructed, by
     * test_expiry_sweep_compensation_end_to_end() below).
     *
     * @param  array<int, array{stock: int, price: float, quantity: int}>  $items
     * @return array{order: Order, payment: Payment, variants: array<int, ProductVariant>, stripePaymentIntentId: string}
     */
    private function expirySweepLateSuccessFixture(array $items): array
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $lineItems = [];
        $variants = [];

        foreach ($items as $spec) {
            $product = Product::factory()->forStore($store)->create();
            $variant = ProductVariant::factory()->forProduct($product)->create([
                'price' => $spec['price'],
                'status' => CatalogStatus::Active,
            ]);

            app(InventoryAdjustmentService::class)->adjust(
                $variant, $spec['stock'], InventoryTransactionReason::Restock, null, null
            );

            $lineItems[] = ['variant' => $variant, 'quantity' => $spec['quantity']];
            $variants[] = $variant;
        }

        $stripePaymentIntentId = 'pi_test_'.Str::random(24);

        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            $lineItems,
            [
                'recipient_name' => 'Jane Doe',
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
            $stripePaymentIntentId,
        );

        $payment = $order->payments->first();

        foreach ($order->items as $item) {
            app(InventoryAdjustmentService::class)->adjust(
                $item->variant, $item->quantity, InventoryTransactionReason::Release, null, null, $item, $payment
            );
        }

        $payment->status = PaymentStatus::Canceled;
        $payment->failure_reason = 'expired';
        $payment->save();

        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'expired';
        $order->cancelled_at = now();
        $order->save();

        // The late webhook succeeding — mirrors
        // StripePaymentWebhookService::recordLatePaymentSucceededAfterExpirySweep()'s
        // real behavior exactly: Payment.status is deliberately NOT touched.
        $order->status_reason = 'payment_succeeded_after_expiry_cancellation';
        $order->save();

        return [
            'order' => $order->fresh(),
            'payment' => $payment->fresh(),
            'variants' => $variants,
            'stripePaymentIntentId' => $stripePaymentIntentId,
        ];
    }

    public function test_expiry_sweep_compensation_refund_resolves_status_reason_and_does_not_double_credit_inventory(): void
    {
        $fixture = $this->expirySweepLateSuccessFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $onHandBefore = Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand;
        $ledgerCountBefore = InventoryTransaction::count();

        $payload = $this->buildRefundEventPayload(
            'evt_expiry_compensation_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        );

        $response = $this->postWebhook($payload);

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['stripe_refund_id' => $refundId, 'status' => 'succeeded']);

        // Inventory was already fully returned by the sweep's own Release —
        // the refund must NOT credit it a second time.
        $this->assertSame($onHandBefore, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertSame($ledgerCountBefore, InventoryTransaction::count());
        $this->assertDatabaseMissing('inventory_transactions', [
            'payment_id' => $fixture['payment']->id,
            'reason' => 'refund',
        ]);

        // Order stays Cancelled, Payment stays Canceled — only status_reason resolves.
        $order = $fixture['order']->fresh();
        $payment = $fixture['payment']->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('payment_refunded_after_expiry_cancellation', $order->status_reason);
        $this->assertSame(PaymentStatus::Canceled, $payment->status);
        $this->assertSame('expired', $payment->failure_reason);
    }

    public function test_expiry_sweep_compensation_duplicate_webhook_does_not_double_credit_or_re_resolve(): void
    {
        $fixture = $this->expirySweepLateSuccessFixture([['stock' => 10, 'price' => 20.00, 'quantity' => 2]]);
        $variant = $fixture['variants'][0];
        $refundId = 're_test_'.Str::random(16);

        $this->postWebhook($this->buildRefundEventPayload(
            'evt_expiry_dup_1', 'refund.created', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ))->assertOk();

        $onHandAfterFirst = Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand;
        $ledgerCountAfterFirst = InventoryTransaction::count();

        // A different event id redelivering the same already-succeeded refund.
        $response = $this->postWebhook($this->buildRefundEventPayload(
            'evt_expiry_dup_2', 'refund.updated', $fixture['stripePaymentIntentId'], $refundId, 'succeeded', 4000
        ));

        $response->assertOk();
        $this->assertSame($onHandAfterFirst, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertSame($ledgerCountAfterFirst, InventoryTransaction::count());
        $this->assertSame('payment_refunded_after_expiry_cancellation', $fixture['order']->fresh()->status_reason);
        $this->assertSame(PaymentStatus::Canceled, $fixture['payment']->fresh()->status);
    }

    /**
     * The full realistic chain, end to end, with no constructed
     * intermediate state: real checkout -> real expiry sweep -> real late
     * payment_intent.succeeded webhook (9E-3 detection, already proven in
     * isolation by test_9e3_end_to_end_expiry_sweep_late_payment_detection
     * above) -> a real admin-initiated compensating refund through
     * RefundService::refundLateSucceededExpiredPayment() (the same method
     * RefundController dispatches to) -> a real refund.updated succeeded
     * webhook resolving it.
     */
    public function test_expiry_sweep_compensation_end_to_end(): void
    {
        config(['services.stripe.checkout_expiry_minutes' => 30]);
        $this->app->instance(StripePaymentIntentGateway::class, new FakePaymentIntentGateway);

        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => 20.00,
            'status' => CatalogStatus::Active,
        ]);

        app(InventoryAdjustmentService::class)->adjust(
            $variant, 10, InventoryTransactionReason::Restock, null, null
        );

        $stripePaymentIntentId = 'pi_test_'.Str::random(24);

        $this->travelTo(now()->subMinutes(35));
        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            [['variant' => $variant, 'quantity' => 2]],
            [
                'recipient_name' => 'Jane Doe',
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
            $stripePaymentIntentId,
        );
        $this->travelBack();

        $payment = $order->payments->first();

        // Real expiry sweep — cancels the order, releases inventory, marks
        // the Payment Canceled/'expired'.
        app(PaymentExpirySweepService::class)->sweep();

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Canceled, $payment->status);

        // The residual race: Stripe's real PaymentIntent actually succeeds
        // after the sweep already canceled it locally (9E-3 detection).
        $succeededPayload = [
            'id' => 'evt_expiry_e2e_succeeded',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'created' => now()->timestamp,
            'data' => [
                'object' => [
                    'id' => $stripePaymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => 4000,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                ],
            ],
        ];
        $this->postWebhook($succeededPayload)->assertOk();

        $order->refresh();
        $this->assertSame('payment_succeeded_after_expiry_cancellation', $order->status_reason);

        // Admin-initiated compensating refund, through the real
        // RefundService — the same code path RefundController dispatches to.
        $this->fakeRefundGateway();
        $result = app(RefundService::class)->refundLateSucceededExpiredPayment(
            $order, 'Late payment after expiry', 'expiry-e2e-key', null
        );
        $refund = $result['refund'];
        $this->assertSame('pending', $refund->status->value);

        // Payment must still be Canceled right after the refund is
        // created — this method never touches Payment.status.
        $this->assertSame(PaymentStatus::Canceled, $payment->fresh()->status);

        $refundSucceededPayload = $this->buildRefundEventPayload(
            'evt_expiry_e2e_refund_succeeded', 'refund.updated', $stripePaymentIntentId, $refund->stripe_refund_id, 'succeeded', 4000
        );
        $this->postWebhook($refundSucceededPayload)->assertOk();

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status); // never reopened, never Refunded
        $this->assertSame('payment_refunded_after_expiry_cancellation', $order->status_reason);
        $this->assertSame(PaymentStatus::Canceled, $payment->status); // still never Succeeded
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'succeeded']);
        // Inventory was already released by the sweep — not double-credited.
        $this->assertSame(10, Inventory::where('product_variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertDatabaseMissing('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'payment_id' => $payment->id,
            'reason' => 'refund',
        ]);
    }
}
