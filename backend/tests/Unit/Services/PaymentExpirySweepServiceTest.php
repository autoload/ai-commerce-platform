<?php

namespace Tests\Unit\Services;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\InventoryAdjustmentService;
use App\Services\PaymentExpirySweepService;
use App\Services\StripePaymentIntentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\Doubles\FakePaymentIntentGateway;
use Tests\TestCase;

/**
 * Phase 6 — database-design.md §12 ("Expiry Semantics"). Fixtures build the
 * exact state a real stale checkout would leave: a Pending Order, one
 * OrderItem, and a `requires_payment` Payment with inventory already
 * claimed (Checkout reason) — the same shape CheckoutOrderCreationService
 * itself produces, built by hand here since this suite only needs to
 * exercise the sweep, not checkout again.
 *
 * Uses Carbon time-travel (travelTo/travelBack) to control Payment.created_at
 * relative to the configured expiry window — the first use of this
 * technique in this test suite, since nothing before Phase 6 needed to
 * simulate the passage of time.
 */
class PaymentExpirySweepServiceTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private const WINDOW_MINUTES = 30;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.checkout_expiry_minutes' => self::WINDOW_MINUTES]);
    }

    private function fakeGateway(): FakePaymentIntentGateway
    {
        $fake = new FakePaymentIntentGateway;
        $this->app->instance(StripePaymentIntentGateway::class, $fake);

        return $fake;
    }

    private function service(): PaymentExpirySweepService
    {
        return app(PaymentExpirySweepService::class);
    }

    private function activeVariantWithStock(Store $store, int $stock, float $price = 25.00): ProductVariant
    {
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => $price,
            'status' => CatalogStatus::Active,
        ]);

        app(InventoryAdjustmentService::class)->adjust(
            $variant, $stock, InventoryTransactionReason::Restock, null, null
        );

        return $variant;
    }

    /**
     * Builds a Pending Order + OrderItem + `requires_payment` Payment with
     * inventory already claimed (Checkout reason), with the Payment's
     * created_at set to $createdAgo minutes in the past — the sweep's
     * anchor per §12.
     */
    private function stalePendingOrder(Store $store, Customer $customer, ProductVariant $variant, int $quantity, float $unitPrice, int $createdMinutesAgo): array
    {
        $order = Order::factory()->forStore($store)->create();
        $order->customer_id = $customer->id;
        $order->customer_name = $customer->name;
        $order->customer_email = $customer->email;
        $order->subtotal = round($unitPrice * $quantity, 2);
        $order->total = round($unitPrice * $quantity, 2);
        $order->save();

        $item = OrderItem::factory()->forOrder($order)->create();
        $item->product_id = $variant->product_id;
        $item->product_variant_id = $variant->id;
        $item->unit_price = $unitPrice;
        $item->quantity = $quantity;
        $item->line_total = round($unitPrice * $quantity, 2);
        $item->save();

        $this->travelTo(now()->subMinutes($createdMinutesAgo));

        $payment = Payment::factory()->forOrder($order)->create();

        app(InventoryAdjustmentService::class)->adjust(
            $variant, -$quantity, InventoryTransactionReason::Checkout, null, null, $item, $payment
        );

        $this->travelBack();

        return [$order->refresh(), $payment->refresh()];
    }

    public function test_cancels_an_expired_order_and_releases_its_inventory(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        [$order, $payment] = $this->stalePendingOrder($store, $customer, $variant, 3, 20.00, self::WINDOW_MINUTES + 5);

        $counts = $this->service()->sweep();

        $this->assertSame(['processed' => 1, 'cancelled' => 1, 'deferred' => 0, 'skipped' => 0, 'errors' => 0], $counts);

        $order->refresh();
        $payment->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('expired', $order->status_reason);
        $this->assertNotNull($order->cancelled_at);

        $this->assertSame(PaymentStatus::Canceled, $payment->status);
        $this->assertSame('expired', $payment->failure_reason);

        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 10]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'reason' => 'release',
            'payment_id' => $payment->id,
            'delta' => 3,
        ]);

        $this->assertCount(1, $fake->cancelCalls);
        $this->assertSame($payment->stripe_payment_intent_id, $fake->cancelCalls[0]);
    }

    public function test_does_not_touch_a_payment_still_within_the_window(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        [$order, $payment] = $this->stalePendingOrder($store, $customer, $variant, 2, 20.00, self::WINDOW_MINUTES - 5);

        $counts = $this->service()->sweep();

        $this->assertSame(['processed' => 0, 'cancelled' => 0, 'deferred' => 0, 'skipped' => 0, 'errors' => 0], $counts);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(PaymentStatus::RequiresPayment, $payment->refresh()->status);
        $this->assertCount(0, $fake->retrieveCalls);
        $this->assertCount(0, $fake->cancelCalls);
    }

    public function test_never_sweeps_a_processing_payment_even_if_old(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        [$order, $payment] = $this->stalePendingOrder($store, $customer, $variant, 1, 20.00, self::WINDOW_MINUTES * 4);

        $payment->status = PaymentStatus::Processing;
        $payment->save();

        $counts = $this->service()->sweep();

        $this->assertSame(['processed' => 0, 'cancelled' => 0, 'deferred' => 0, 'skipped' => 0, 'errors' => 0], $counts);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Processing, $payment->refresh()->status);
        $this->assertCount(0, $fake->retrieveCalls);
    }

    public function test_defers_when_stripe_reports_the_payment_intent_has_moved_past_abandoned(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        [$order, $payment] = $this->stalePendingOrder($store, $customer, $variant, 2, 20.00, self::WINDOW_MINUTES + 5);

        // Stripe's side has already moved on (e.g. a 3-D Secure challenge
        // in flight) even though the payment_intent.processing webhook
        // hasn't reached this app yet — the sweep must defer, not cancel.
        $fake->willReturnOnRetrieve(PaymentIntent::constructFrom([
            'id' => $payment->stripe_payment_intent_id,
            'status' => 'processing',
        ]));

        $counts = $this->service()->sweep();

        $this->assertSame(['processed' => 1, 'cancelled' => 0, 'deferred' => 1, 'skipped' => 0, 'errors' => 0], $counts);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(PaymentStatus::RequiresPayment, $payment->refresh()->status);
        $this->assertCount(0, $fake->cancelCalls);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 8]);
    }

    public function test_skips_an_order_that_is_no_longer_pending(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        [$order, $payment] = $this->stalePendingOrder($store, $customer, $variant, 1, 20.00, self::WINDOW_MINUTES + 5);

        // A merchant cancelled the order through the existing Block 4C
        // endpoint while the Payment itself was never touched.
        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'merchant_cancelled';
        $order->cancelled_at = now();
        $order->save();

        $counts = $this->service()->sweep();

        $this->assertSame(['processed' => 1, 'cancelled' => 0, 'deferred' => 0, 'skipped' => 1, 'errors' => 0], $counts);
        $this->assertSame('merchant_cancelled', $order->refresh()->status_reason);
        $this->assertSame(PaymentStatus::RequiresPayment, $payment->refresh()->status);
        $this->assertCount(0, $fake->retrieveCalls);
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        $this->stalePendingOrder($store, $customer, $variant, 2, 20.00, self::WINDOW_MINUTES + 5);

        $first = $this->service()->sweep();
        $second = $this->service()->sweep();

        $this->assertSame(1, $first['cancelled']);
        $this->assertSame(['processed' => 0, 'cancelled' => 0, 'deferred' => 0, 'skipped' => 0, 'errors' => 0], $second);
        $this->assertCount(1, $fake->cancelCalls);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 10]);
    }

    public function test_one_candidates_stripe_failure_does_not_block_the_others(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer1 = Customer::factory()->forStore($store)->create();
        $customer2 = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);

        [$order1, $payment1] = $this->stalePendingOrder($store, $customer1, $variant, 1, 20.00, self::WINDOW_MINUTES + 5);
        [$order2, $payment2] = $this->stalePendingOrder($store, $customer2, $variant, 1, 20.00, self::WINDOW_MINUTES + 5);

        $fake->willThrowOnRetrieve(ApiConnectionException::factory('boom'));

        $counts = $this->service()->sweep();

        // Both candidates attempted retrieve(); the fake throws on every
        // call (it has no per-id scripting), so both fail and are counted
        // as errors — proving one candidate's failure does not abort the
        // batch or leave the loop early.
        $this->assertSame(2, $counts['processed']);
        $this->assertSame(2, $counts['errors']);
        $this->assertSame(OrderStatus::Pending, $order1->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $order2->refresh()->status);
        $this->assertSame(PaymentStatus::RequiresPayment, $payment1->refresh()->status);
        $this->assertSame(PaymentStatus::RequiresPayment, $payment2->refresh()->status);
    }

    public function test_still_cancels_locally_even_if_the_best_effort_stripe_cancel_fails(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        [$order, $payment] = $this->stalePendingOrder($store, $customer, $variant, 1, 20.00, self::WINDOW_MINUTES + 5);

        $fake->willThrowOnCancel(ApiConnectionException::factory('stripe unreachable'));

        $counts = $this->service()->sweep();

        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(0, $counts['errors']);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Canceled, $payment->refresh()->status);
    }

    public function test_payment_row_locking_is_used_during_processing(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        $this->stalePendingOrder($store, $customer, $variant, 1, 20.00, self::WINDOW_MINUTES + 5);

        $queryLog = [];
        DB::listen(function ($query) use (&$queryLog) {
            $queryLog[] = $query->sql;
        });

        $this->service()->sweep();

        $lockedPayments = collect($queryLog)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'from `payments`') && str_contains(strtolower($sql), 'for update')
        );
        $lockedOrders = collect($queryLog)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'from `orders`') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertTrue($lockedPayments, 'Expected a SELECT ... FOR UPDATE against payments.');
        $this->assertTrue($lockedOrders, 'Expected a SELECT ... FOR UPDATE against orders.');
    }
}
