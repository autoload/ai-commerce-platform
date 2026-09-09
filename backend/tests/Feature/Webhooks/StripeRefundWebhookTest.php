<?php

namespace Tests\Feature\Webhooks;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Stripe\WebhookSignature;
use Tests\Concerns\CreatesTenantFixtures;
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
}
