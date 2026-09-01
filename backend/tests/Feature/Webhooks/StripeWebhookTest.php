<?php

namespace Tests\Feature\Webhooks;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\CheckoutOrderCreationService;
use App\Services\InventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Stripe\WebhookSignature;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * STEP 3C — POST /api/webhooks/stripe. Exercises the real
 * Stripe\Webhook::constructEvent()/WebhookSignature verification path end
 * to end (no fake — signature verification is pure local HMAC, no network
 * call), against a fixed test webhook secret configured in setUp(). Every
 * fixture Payment is produced by the real CheckoutOrderCreationService
 * (unmodified), so the inventory ledger rows the release logic depends on
 * are genuine, not hand-built.
 */
class StripeWebhookTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret_for_step_3c';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /**
     * @return array{order: Order, payment: Payment, variant: ProductVariant, stripePaymentIntentId: string}
     */
    private function checkoutFixture(int $stock = 10, float $price = 20.00, int $quantity = 2): array
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => $price,
            'status' => CatalogStatus::Active,
        ]);

        app(InventoryAdjustmentService::class)->adjust(
            $variant, $stock, InventoryTransactionReason::Restock, null, null
        );

        $stripePaymentIntentId = 'pi_test_'.Str::random(24);

        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            [['variant' => $variant, 'quantity' => $quantity]],
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

        return [
            'order' => $order,
            'payment' => $order->payments->first(),
            'variant' => $variant,
            'stripePaymentIntentId' => $stripePaymentIntentId,
        ];
    }

    /**
     * @param  array<string, mixed>  $objectExtra
     * @return array<string, mixed>
     */
    private function buildEventPayload(string $eventId, string $type, string $paymentIntentId, array $objectExtra = []): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => now()->timestamp,
            'data' => [
                'object' => array_merge([
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => 1000,
                    'currency' => 'usd',
                ], $objectExtra),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function postWebhook(array $eventPayload, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($eventPayload, JSON_THROW_ON_ERROR);

        return $this->postRawWebhook($body, $secret, $timestamp);
    }

    private function postRawWebhook(string $rawBody, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $signature = WebhookSignature::generateSignatureHeader($rawBody, $secret ?? self::WEBHOOK_SECRET, $timestamp);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $rawBody);
    }

    // -----------------------------------------------------------------
    // Core event handling
    // -----------------------------------------------------------------

    public function test_valid_succeeded_webhook_transitions_payment_and_order(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, price: 20.00, quantity: 3);

        $payload = $this->buildEventPayload('evt_succeeded_1', 'payment_intent.succeeded', $fixture['stripePaymentIntentId'], [
            'amount' => 6000,
            'status' => 'succeeded',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $fixture['order']->refresh();

        $this->assertSame('succeeded', $fixture['payment']->status->value);
        $this->assertSame('paid', $fixture['order']->status->value);
        $this->assertNotNull($fixture['order']->paid_at);

        // Inventory untouched by success -- already claimed at checkout.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseMissing('inventory_transactions', [
            'product_variant_id' => $fixture['variant']->id,
            'reason' => 'release',
        ]);

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_succeeded_1',
            'type' => 'payment_intent.succeeded',
        ]);
    }

    public function test_processing_webhook_transitions_payment_only(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 2, price: 10.00);

        $payload = $this->buildEventPayload('evt_processing_1', 'payment_intent.processing', $fixture['stripePaymentIntentId'], [
            'status' => 'processing',
        ]);

        $response = $this->postWebhook($payload);
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $fixture['order']->refresh();

        $this->assertSame('processing', $fixture['payment']->status->value);
        $this->assertSame('pending', $fixture['order']->status->value);
        $this->assertNull($fixture['order']->paid_at);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 8]);
    }

    public function test_payment_failed_webhook_releases_inventory_exactly_once(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 4, price: 15.00);

        $payload = $this->buildEventPayload('evt_failed_1', 'payment_intent.payment_failed', $fixture['stripePaymentIntentId'], [
            'status' => 'requires_payment_method',
            'last_payment_error' => [
                'message' => 'Your card was declined.',
                'code' => 'card_declined',
                'type' => 'card_error',
            ],
        ]);

        $response = $this->postWebhook($payload);
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $fixture['order']->refresh();

        $this->assertSame('failed', $fixture['payment']->status->value);
        $this->assertSame('Your card was declined.', $fixture['payment']->failure_reason);
        $this->assertSame('pending', $fixture['order']->status->value);

        // Claimed 4 at checkout, released 4 on failure -- back to full stock.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 10]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $fixture['variant']->id,
            'payment_id' => $fixture['payment']->id,
            'reason' => 'release',
            'delta' => 4,
        ]);
    }

    public function test_canceled_webhook_releases_inventory_exactly_once(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 2, price: 10.00);

        $payload = $this->buildEventPayload('evt_canceled_1', 'payment_intent.canceled', $fixture['stripePaymentIntentId'], [
            'status' => 'canceled',
            'cancellation_reason' => 'abandoned',
        ]);

        $response = $this->postWebhook($payload);
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $fixture['order']->refresh();

        $this->assertSame('canceled', $fixture['payment']->status->value);
        $this->assertSame('abandoned', $fixture['payment']->failure_reason);
        $this->assertSame('pending', $fixture['order']->status->value);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 10]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $fixture['variant']->id,
            'payment_id' => $fixture['payment']->id,
            'reason' => 'release',
            'delta' => 2,
        ]);
    }

    // -----------------------------------------------------------------
    // Signature / payload verification
    // -----------------------------------------------------------------

    public function test_invalid_signature_is_rejected(): void
    {
        $fixture = $this->checkoutFixture();
        $payload = $this->buildEventPayload('evt_bad_sig', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']);

        $response = $this->postWebhook($payload, secret: 'whsec_completely_wrong_secret');

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);

        $fixture['payment']->refresh();
        $this->assertSame('requires_payment', $fixture['payment']->status->value);
    }

    public function test_malformed_json_payload_is_rejected(): void
    {
        $rawBody = '{this is not valid json';

        $response = $this->postRawWebhook($rawBody);

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_expired_signature_timestamp_is_rejected(): void
    {
        $fixture = $this->checkoutFixture();
        $payload = $this->buildEventPayload('evt_expired_1', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->postRawWebhook($body, timestamp: now()->subMinutes(10)->timestamp);

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_missing_signature_header_is_rejected_safely(): void
    {
        $fixture = $this->checkoutFixture();
        $payload = $this->buildEventPayload('evt_missing_sig', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_whitespace_signature_header_is_rejected_safely(): void
    {
        $fixture = $this->checkoutFixture();
        $payload = $this->buildEventPayload('evt_whitespace_sig', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => '   ',
        ], $body);

        $response->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    // -----------------------------------------------------------------
    // Idempotency / unknown PaymentIntent / unsupported events
    // -----------------------------------------------------------------

    public function test_duplicate_event_is_processed_only_once(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 3, price: 10.00);
        $payload = $this->buildEventPayload('evt_dup_1', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']);

        $this->postWebhook($payload)->assertStatus(200);
        $second = $this->postWebhook($payload);
        $second->assertStatus(200);

        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertDatabaseCount('payments', 1);

        $fixture['order']->refresh();
        $this->assertSame('paid', $fixture['order']->status->value);
    }

    /**
     * The other duplicate-delivery test (test_duplicate_event_is_processed_only_once)
     * uses payment_intent.succeeded, which never touches inventory at all --
     * it proves event-level dedup in general but not specifically that a
     * duplicate failure/cancellation can't release inventory twice. This
     * test proves that directly: duplicate failure webhook != second
     * inventory release.
     */
    public function test_duplicate_payment_failed_event_does_not_release_inventory_twice(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 4, price: 10.00);

        $payload = $this->buildEventPayload('evt_dup_release_1', 'payment_intent.payment_failed', $fixture['stripePaymentIntentId'], [
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ]);

        $first = $this->postWebhook($payload);
        $first->assertStatus(200);

        $fixture['payment']->refresh();
        $this->assertSame('failed', $fixture['payment']->status->value);
        $this->assertSame(
            1,
            InventoryTransaction::where('payment_id', $fixture['payment']->id)->where('reason', 'release')->count()
        );
        $this->assertDatabaseHas('inventory_transactions', [
            'payment_id' => $fixture['payment']->id,
            'reason' => 'release',
            'delta' => 4,
        ]);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 10]);

        // The exact same event, redelivered -- same stripe_event_id.
        $second = $this->postWebhook($payload);
        $second->assertStatus(200);

        $fixture['payment']->refresh();
        $this->assertSame('failed', $fixture['payment']->status->value);

        // Still exactly one release row -- not two -- and the quantity is
        // unchanged from after the first delivery, not restored a second time.
        $this->assertSame(
            1,
            InventoryTransaction::where('payment_id', $fixture['payment']->id)->where('reason', 'release')->count()
        );
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 10]);
    }

    public function test_unknown_payment_intent_returns_404_and_rolls_back(): void
    {
        $payload = $this->buildEventPayload('evt_unknown_1', 'payment_intent.succeeded', 'pi_does_not_exist_locally');

        $response = $this->postWebhook($payload);

        $response->assertStatus(404);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_unsupported_event_type_is_recorded_but_ignored(): void
    {
        $fixture = $this->checkoutFixture();

        $payload = [
            'id' => 'evt_unsupported_1',
            'object' => 'event',
            'type' => 'charge.succeeded',
            'created' => now()->timestamp,
            'data' => ['object' => ['id' => 'ch_test_123', 'object' => 'charge']],
        ];

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_unsupported_1',
            'type' => 'charge.succeeded',
        ]);

        $fixture['payment']->refresh();
        $this->assertSame('requires_payment', $fixture['payment']->status->value);
    }

    // -----------------------------------------------------------------
    // Out-of-order / terminal-state protection
    // -----------------------------------------------------------------

    public function test_stale_processing_event_after_succeeded_does_not_regress(): void
    {
        $fixture = $this->checkoutFixture();

        $this->postWebhook($this->buildEventPayload('evt_seq_1', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']))
            ->assertStatus(200);

        $response = $this->postWebhook($this->buildEventPayload('evt_seq_2', 'payment_intent.processing', $fixture['stripePaymentIntentId']));
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $this->assertSame('succeeded', $fixture['payment']->status->value);
        $this->assertDatabaseCount('stripe_webhook_events', 2);
    }

    public function test_failed_payment_cannot_later_become_succeeded(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 3, price: 10.00);

        $this->postWebhook($this->buildEventPayload('evt_seq_3', 'payment_intent.payment_failed', $fixture['stripePaymentIntentId']))
            ->assertStatus(200);

        $response = $this->postWebhook($this->buildEventPayload('evt_seq_4', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']));
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $fixture['order']->refresh();

        $this->assertSame('failed', $fixture['payment']->status->value);
        $this->assertSame('pending', $fixture['order']->status->value);

        // Exactly one release happened (from the failed event) -- the
        // later no-op succeeded event must not touch inventory again.
        $this->assertDatabaseCount('inventory_transactions', 3); // restock + checkout + release
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 10]);
    }

    public function test_canceled_payment_cannot_later_become_succeeded(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 3, price: 10.00);

        // Pre-condition set directly, not via a webhook -- isolates the
        // transition under test (Canceled -> Succeeded) from however the
        // Payment got to Canceled in the first place.
        $fixture['payment']->status = PaymentStatus::Canceled;
        $fixture['payment']->save();

        $response = $this->postWebhook($this->buildEventPayload('evt_term_1', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']));
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $fixture['order']->refresh();

        $this->assertSame('canceled', $fixture['payment']->status->value);
        $this->assertSame('pending', $fixture['order']->status->value);
        $this->assertNull($fixture['order']->paid_at);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 7]);
    }

    public function test_succeeded_payment_cannot_later_become_failed(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 3, price: 10.00);

        $fixture['payment']->status = PaymentStatus::Succeeded;
        $fixture['payment']->save();

        $response = $this->postWebhook($this->buildEventPayload('evt_term_2', 'payment_intent.payment_failed', $fixture['stripePaymentIntentId'], [
            'last_payment_error' => ['message' => 'Should never be applied.'],
        ]));
        $response->assertStatus(200);

        $fixture['payment']->refresh();

        $this->assertSame('succeeded', $fixture['payment']->status->value);
        $this->assertNull($fixture['payment']->failure_reason);

        // No release -- inventory must stay at the claimed level, not be restored.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseMissing('inventory_transactions', [
            'payment_id' => $fixture['payment']->id,
            'reason' => 'release',
        ]);
    }

    public function test_succeeded_payment_cannot_later_become_canceled(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 3, price: 10.00);

        $fixture['payment']->status = PaymentStatus::Succeeded;
        $fixture['payment']->save();

        $response = $this->postWebhook($this->buildEventPayload('evt_term_3', 'payment_intent.canceled', $fixture['stripePaymentIntentId'], [
            'cancellation_reason' => 'should_never_apply',
        ]));
        $response->assertStatus(200);

        $fixture['payment']->refresh();

        $this->assertSame('succeeded', $fixture['payment']->status->value);
        $this->assertNull($fixture['payment']->failure_reason);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseMissing('inventory_transactions', [
            'payment_id' => $fixture['payment']->id,
            'reason' => 'release',
        ]);
    }

    public function test_succeeded_webhook_does_not_regress_a_non_pending_order(): void
    {
        $fixture = $this->checkoutFixture();
        $order = $fixture['order'];
        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'merchant_cancelled';
        $order->save();

        $response = $this->postWebhook($this->buildEventPayload('evt_np_1', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']));
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $order->refresh();

        $this->assertSame('succeeded', $fixture['payment']->status->value);
        $this->assertSame('cancelled', $order->status->value);
    }

    // -----------------------------------------------------------------
    // Predecessor matrix: RequiresPayment/Processing -> each terminal state
    // -----------------------------------------------------------------

    public function test_succeeded_is_allowed_from_processing(): void
    {
        $fixture = $this->checkoutFixture();

        $this->postWebhook($this->buildEventPayload('evt_pred_1', 'payment_intent.processing', $fixture['stripePaymentIntentId']))
            ->assertStatus(200);

        $response = $this->postWebhook($this->buildEventPayload('evt_pred_2', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']));
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $this->assertSame('succeeded', $fixture['payment']->status->value);
    }

    public function test_failed_is_allowed_from_processing(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 2, price: 10.00);

        $this->postWebhook($this->buildEventPayload('evt_pred_3', 'payment_intent.processing', $fixture['stripePaymentIntentId']))
            ->assertStatus(200);

        $response = $this->postWebhook($this->buildEventPayload('evt_pred_4', 'payment_intent.payment_failed', $fixture['stripePaymentIntentId']));
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $this->assertSame('failed', $fixture['payment']->status->value);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 10]);
    }

    public function test_canceled_is_allowed_from_processing(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 2, price: 10.00);

        $this->postWebhook($this->buildEventPayload('evt_pred_5', 'payment_intent.processing', $fixture['stripePaymentIntentId']))
            ->assertStatus(200);

        $response = $this->postWebhook($this->buildEventPayload('evt_pred_6', 'payment_intent.canceled', $fixture['stripePaymentIntentId'], [
            'cancellation_reason' => 'abandoned',
        ]));
        $response->assertStatus(200);

        $fixture['payment']->refresh();
        $this->assertSame('canceled', $fixture['payment']->status->value);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixture['variant']->id, 'quantity_on_hand' => 10]);
    }

    // -----------------------------------------------------------------
    // Release scoping, locking, and crash-boundary proofs
    // -----------------------------------------------------------------

    public function test_inventory_release_is_scoped_to_the_specific_payment(): void
    {
        $fixtureA = $this->checkoutFixture(stock: 10, quantity: 3, price: 10.00);
        $fixtureB = $this->checkoutFixture(stock: 10, quantity: 5, price: 10.00);

        $this->postWebhook($this->buildEventPayload('evt_scope_1', 'payment_intent.payment_failed', $fixtureA['stripePaymentIntentId']))
            ->assertStatus(200);

        // A's stock is restored...
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixtureA['variant']->id, 'quantity_on_hand' => 10]);
        // ...B's claim is completely untouched.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $fixtureB['variant']->id, 'quantity_on_hand' => 5]);
        $fixtureB['payment']->refresh();
        $this->assertSame('requires_payment', $fixtureB['payment']->status->value);
        $this->assertDatabaseMissing('inventory_transactions', [
            'payment_id' => $fixtureB['payment']->id,
            'reason' => 'release',
        ]);
    }

    public function test_payment_row_locking_is_used_during_processing(): void
    {
        $fixture = $this->checkoutFixture();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->postWebhook($this->buildEventPayload('evt_lock_1', 'payment_intent.processing', $fixture['stripePaymentIntentId']))
            ->assertStatus(200);

        $lockingQueryFound = collect($queries)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'payments') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertTrue($lockingQueryFound, 'Expected a SELECT ... FOR UPDATE query against payments during webhook processing.');
    }

    /**
     * Forces a genuine failure *inside* the locked transaction, after the
     * Payment row has already been updated in memory, to prove
     * DB::transaction() rolls back the event row, the Payment change, and
     * everything else together -- not just that validation can reject a
     * request beforehand. Mirrors InventoryManagementTest's established
     * forced-failure pattern.
     */
    public function test_forced_failure_during_processing_rolls_back_everything(): void
    {
        $fixture = $this->checkoutFixture(stock: 10, quantity: 2, price: 10.00);

        Order::saving(function () {
            throw new RuntimeException('Simulated failure to verify webhook transaction rollback.');
        });

        try {
            $response = $this->postWebhook($this->buildEventPayload('evt_rollback_1', 'payment_intent.succeeded', $fixture['stripePaymentIntentId']));

            $response->assertStatus(500);

            $this->assertDatabaseCount('stripe_webhook_events', 0);
            $fixture['payment']->refresh();
            $this->assertSame('requires_payment', $fixture['payment']->status->value);
        } finally {
            Order::flushEventListeners();
        }
    }
}
