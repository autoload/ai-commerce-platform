<?php

namespace Tests\Feature\Customer;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Customer\RetryPaymentController;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\InventoryAdjustmentService;
use App\Services\StripePaymentIntentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\Doubles\FakePaymentIntentGateway;
use Tests\TestCase;

/**
 * Phase 3 STEP 3D — POST /api/orders/{order}/payment-retry. Uses the same
 * FakePaymentIntentGateway double as CheckoutTest — no real Stripe
 * credentials or network traffic anywhere in this suite.
 *
 * Fixtures deliberately reconstruct the exact post-checkout,
 * post-webhook-failure state a real retry would find: an Order (Pending),
 * one terminal (Failed) Payment from the original attempt, and inventory
 * already released back by StripePaymentWebhookService::
 * releaseInventoryForPayment() — built by hand here (not by driving the
 * full checkout+webhook HTTP flow) since STEP 3A/3B/3C are frozen and this
 * suite only needs to exercise STEP 3D's own service/controller.
 */
class PaymentRetryTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private function fakeGateway(): FakePaymentIntentGateway
    {
        $fake = new FakePaymentIntentGateway;
        $this->app->instance(StripePaymentIntentGateway::class, $fake);

        return $fake;
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

    private function customerToken(Customer $customer): string
    {
        return $customer->createToken('customer-session')->plainTextToken;
    }

    /**
     * Builds a Pending Order (bound to $customer) with one OrderItem for
     * $variant, plus one terminal Failed Payment representing the original
     * checkout attempt — its inventory claim already made and released,
     * exactly as StripePaymentWebhookService would have left it after a
     * payment_intent.payment_failed webhook. The variant's stock ends up
     * back at $stock (claim then release nets to zero change), ready for
     * a retry's own re-claim.
     */
    private function orderReadyForRetry(Store $store, Customer $customer, ProductVariant $variant, int $quantity, float $unitPrice): Order
    {
        $order = Order::factory()->forStore($store)->create();
        $order->customer_id = $customer->id;
        $order->customer_name = $customer->name;
        $order->customer_email = $customer->email;
        $order->subtotal = round($unitPrice * $quantity, 2);
        $order->total = round($unitPrice * $quantity, 2);
        $order->save();

        // forOrder()'s afterMaking hook hardcodes unit_price/quantity/
        // line_total after construction, so attributes passed to create()
        // for those columns would be silently overwritten — set them via
        // direct property assignment afterward instead, the same
        // established pattern OrderFactory's own tests use for `status`.
        $item = OrderItem::factory()->forOrder($order)->create();
        $item->product_id = $variant->product_id;
        $item->product_variant_id = $variant->id;
        $item->unit_price = $unitPrice;
        $item->quantity = $quantity;
        $item->line_total = round($unitPrice * $quantity, 2);
        $item->save();

        $originalPayment = Payment::factory()->forOrder($order)->create();

        $inventoryService = app(InventoryAdjustmentService::class);
        $inventoryService->adjust($variant, -$quantity, InventoryTransactionReason::Checkout, null, null, $item, $originalPayment);
        $inventoryService->adjust($variant, $quantity, InventoryTransactionReason::Release, null, null, $item, $originalPayment);

        $originalPayment->status = PaymentStatus::Failed;
        $originalPayment->failure_reason = 'card_declined';
        $originalPayment->save();

        return $order->refresh();
    }

    private function retry(string $token, Order $order, ?string $idempotencyKey = 'retry-key-1'): TestResponse
    {
        $request = $this->withToken($token);

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        return $request->postJson("/api/orders/{$order->id}/payment-retry");
    }

    public function test_happy_path_creates_new_payment_and_reclaims_inventory(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 3, 20.00);
        $token = $this->customerToken($customer);

        $response = $this->retry($token, $order, 'retry-happy-1');

        $response->assertStatus(201);
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonPath('data.status', 'pending');
        $this->assertNotEmpty($response->json('payment.client_secret'));
        $this->assertNotEmpty($response->json('payment.stripe_payment_intent_id'));

        $this->assertCount(1, $fake->calls);
        $this->assertSame(6000, $fake->calls[0]['params']['amount']);
        $expectedStripeKey = hash('sha256', "retry:{$order->id}:retry-happy-1");
        $this->assertSame($expectedStripeKey, $fake->calls[0]['idempotency_key']);

        $this->assertDatabaseCount('payments', 2); // original (failed) + this retry
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'idempotency_key' => 'retry-happy-1',
            'status' => 'requires_payment',
            'stripe_payment_intent_id' => $response->json('payment.stripe_payment_intent_id'),
        ]);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'reason' => 'checkout',
            'payment_id' => Payment::where('idempotency_key', 'retry-happy-1')->first()->id,
            'delta' => -3,
        ]);
    }

    public function test_missing_idempotency_key_is_rejected_before_calling_stripe(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 1, 10.00);
        $token = $this->customerToken($customer);

        $response = $this->retry($token, $order, null);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
        $this->assertCount(0, $fake->calls);
    }

    public function test_order_not_pending_is_rejected_before_calling_stripe(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 1, 10.00);
        $order->status = OrderStatus::Paid;
        $order->paid_at = now();
        $order->save();
        $token = $this->customerToken($customer);

        $response = $this->retry($token, $order);

        $response->assertStatus(422);
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_order_with_no_prior_terminal_payment_is_rejected(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);

        // A Pending order with NO Payment at all — never went through
        // checkout's own Payment-creation step (a state that shouldn't
        // occur via the real checkout flow, but the service must not
        // assume it can't).
        $order = Order::factory()->forStore($store)->create();
        $order->customer_id = $customer->id;
        $order->save();

        $token = $this->customerToken($customer);

        $response = $this->retry($token, $order);

        $response->assertStatus(422);
        $this->assertCount(0, $fake->calls);
    }

    public function test_active_payment_with_different_key_is_rejected_as_conflict(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 1, 10.00);

        // Simulates an earlier retry attempt still in flight (its own
        // Payment row already inserted) under a different idempotency key.
        // forOrder()'s afterMaking hook hardcodes `status` after
        // construction, so it must be set via direct assignment afterward,
        // not via a create() attribute (which would be silently
        // overwritten back to RequiresPayment).
        $inFlight = Payment::factory()->forOrder($order)->create(['idempotency_key' => 'earlier-retry-key']);
        $inFlight->status = PaymentStatus::Processing;
        $inFlight->save();

        $token = $this->customerToken($customer);

        $response = $this->retry($token, $order, 'a-new-different-key');

        $response->assertStatus(409);
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('payments', 2); // original failed + the in-flight one, no third row
    }

    public function test_same_key_replay_reaches_stripe_again_and_does_not_duplicate_the_payment_row(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 15.00);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 2, 15.00);
        $token = $this->customerToken($customer);

        // Force the fake to behave like real Stripe would for a repeated
        // idempotency key: return the exact same PaymentIntent both times.
        $fixedIntent = PaymentIntent::constructFrom([
            'id' => 'pi_retry_fixed_1',
            'client_secret' => 'pi_retry_fixed_1_secret',
            'amount' => 3000,
            'currency' => 'usd',
            'status' => 'requires_payment_method',
        ]);
        $fake->willReturn($fixedIntent);

        $first = $this->retry($token, $order, 'idem-replay-1');
        $first->assertStatus(201);

        $second = $this->retry($token, $order, 'idem-replay-1');
        $second->assertStatus(200);

        $this->assertSame('pi_retry_fixed_1', $first->json('payment.stripe_payment_intent_id'));
        $this->assertSame('pi_retry_fixed_1', $second->json('payment.stripe_payment_intent_id'));
        $this->assertSame('pi_retry_fixed_1_secret', $second->json('payment.client_secret'));

        // Stripe IS called both times — no short-circuit before Stripe on
        // replay (Option 1) — with the identical derived idempotency key.
        $this->assertCount(2, $fake->calls);
        $this->assertSame($fake->calls[0]['idempotency_key'], $fake->calls[1]['idempotency_key']);

        // But only one Payment row and one inventory claim were persisted.
        $this->assertDatabaseCount('payments', 2); // original failed + this one retry
        $this->assertSame(
            1,
            Payment::where('order_id', $order->id)->where('idempotency_key', 'idem-replay-1')->count()
        );
        $this->assertSame(
            1,
            InventoryTransaction::where('product_variant_id', $variant->id)
                ->where('reason', 'checkout')
                ->where('payment_id', Payment::where('idempotency_key', 'idem-replay-1')->first()->id)
                ->count()
        );
    }

    public function test_insufficient_inventory_on_retry_cancels_the_order(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 5, 20.00);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 5, 20.00);

        // Someone else bought all remaining stock between the original
        // failure and this retry attempt.
        app(InventoryAdjustmentService::class)->adjust(
            $variant, -5, InventoryTransactionReason::Adjustment, 'sold out before retry', null
        );

        $token = $this->customerToken($customer);

        $response = $this->retry($token, $order, 'idem-oos-1');

        $response->assertStatus(422);
        $this->assertCount(1, $fake->calls); // Stripe was already called before the claim failed
        $this->assertDatabaseCount('payments', 1); // only the original — no new Payment row persisted

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('item_no_longer_available', $order->status_reason);
        $this->assertNotNull($order->cancelled_at);
    }

    public function test_stripe_payment_intent_id_collision_across_orders_is_mapped_to_409(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customerA = Customer::factory()->forStore($store)->create();
        $customerB = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 20, 10.00);
        $orderA = $this->orderReadyForRetry($store, $customerA, $variant, 1, 10.00);
        $orderB = $this->orderReadyForRetry($store, $customerB, $variant, 1, 10.00);

        $collidingIntent = PaymentIntent::constructFrom([
            'id' => 'pi_colliding_id',
            'client_secret' => 'pi_colliding_id_secret',
            'amount' => 1000,
            'currency' => 'usd',
            'status' => 'requires_payment_method',
        ]);
        $fake->willReturn($collidingIntent);

        $first = $this->retry($this->customerToken($customerA), $orderA, 'order-a-key');
        $first->assertStatus(201);

        // Laravel's RequestGuard caches its resolved user for the lifetime
        // of the booted Application — without this, the second request
        // below would still resolve as customer A. Same gotcha documented
        // in CustomerTenantIsolationTest.
        Auth::forgetGuards();

        // A structurally near-impossible Stripe id collision on a second,
        // unrelated order/idempotency key — still mapped to a clear 409.
        $second = $this->retry($this->customerToken($customerB), $orderB, 'order-b-key');
        $second->assertStatus(409);

        $this->assertDatabaseCount('payments', 3); // 2 originals (failed) + orderA's one successful retry
    }

    public function test_cannot_retry_another_customers_order(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $owner = Customer::factory()->forStore($store)->create();
        $intruder = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $order = $this->orderReadyForRetry($store, $owner, $variant, 1, 10.00);

        $response = $this->retry($this->customerToken($intruder), $order);

        $response->assertStatus(404);
        $this->assertCount(0, $fake->calls);
    }

    public function test_generic_stripe_api_failure_is_mapped_without_leaking_details(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 1, 10.00);

        $fake->willThrow(ApiConnectionException::factory('Could not connect to Stripe over the internal test network.'));

        $response = $this->retry($this->customerToken($customer), $order);

        $response->assertStatus(502);
        $this->assertDatabaseCount('payments', 1);
        $this->assertStringNotContainsString('Could not connect to Stripe', $response->getContent());
    }

    /**
     * Narrow MySQL 1205 ("Lock wait timeout exceeded") detection —
     * exercised directly against the controller's private helper via
     * reflection, since reliably forcing a real lock-wait timeout requires
     * two genuinely concurrent DB connections (impractical in this
     * single-process suite; the approved design's own empirical proof for
     * the underlying locking behavior was already done separately via raw
     * two-connection SQL, not as a committed test). This proves the
     * detection is exact — matches error 1205 and nothing else.
     */
    public function test_lock_wait_timeout_detection_matches_only_mysql_error_1205(): void
    {
        $this->fakeGateway();
        $controller = app(RetryPaymentController::class);
        $method = new ReflectionMethod(RetryPaymentController::class, 'isLockWaitTimeout');
        $method->setAccessible(true);

        $lockWaitPdoException = new PDOException('Lock wait timeout exceeded; try restarting transaction');
        $lockWaitPdoException->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];
        $lockWaitException = new QueryException('mysql', 'select * from `orders` where `id` = ? for update', [1], $lockWaitPdoException);

        $this->assertTrue($method->invoke($controller, $lockWaitException));

        $deadlockPdoException = new PDOException('Deadlock found when trying to get lock');
        $deadlockPdoException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        $deadlockException = new QueryException('mysql', 'select * from `orders` where `id` = ? for update', [1], $deadlockPdoException);

        $this->assertFalse($method->invoke($controller, $deadlockException));

        $duplicateKeyPdoException = new PDOException("Duplicate entry '1' for key 'payments_order_id_idempotency_key_unique'");
        $duplicateKeyPdoException->errorInfo = ['23000', 1062, "Duplicate entry '1' for key 'payments_order_id_idempotency_key_unique'"];
        $duplicateKeyException = new QueryException('mysql', 'insert into `payments` ...', [], $duplicateKeyPdoException);

        $this->assertFalse($method->invoke($controller, $duplicateKeyException));
    }

    /**
     * M-1 (1/2): proves PaymentRetryService actually acquires the `orders`
     * row lock, and that it is still held (the transaction is still open)
     * at the exact moment the Stripe call happens — not released and
     * reacquired around it. Mirrors CheckoutOrderCreationServiceTest::
     * test_inventory_claim_uses_row_locking's DB::listen() style. A real
     * second DB connection isn't used here (matching the approved design's
     * own precedent — its empirical two-connection proof was done
     * separately, outside the committed suite) — instead, a bespoke
     * StripePaymentIntentGateway double records DB::transactionLevel() and
     * whether the locking query already appears in the query log at the
     * moment Stripe is called, which directly proves the lock/transaction
     * is still open at that point without needing real concurrency.
     */
    public function test_retry_acquires_and_holds_the_order_row_lock_across_the_stripe_call(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 2, 20.00);
        $token = $this->customerToken($customer);

        $queryLog = [];
        DB::listen(function ($query) use (&$queryLog) {
            $queryLog[] = $query->sql;
        });

        $sawOrderLockQuery = function () use (&$queryLog) {
            return collect($queryLog)->contains(
                fn ($sql) => str_contains(strtolower($sql), 'orders') && str_contains(strtolower($sql), 'for update')
            );
        };

        $gateway = new class($sawOrderLockQuery) implements StripePaymentIntentGateway
        {
            public ?int $transactionLevelDuringCall = null;

            public bool $orderLockQuerySeenBeforeCall = false;

            public function __construct(private $sawOrderLockQuery) {}

            public function create(array $params, string $idempotencyKey): PaymentIntent
            {
                $this->transactionLevelDuringCall = DB::transactionLevel();
                $this->orderLockQuerySeenBeforeCall = ($this->sawOrderLockQuery)();

                $id = 'pi_lock_test_'.uniqid();

                return PaymentIntent::constructFrom([
                    'id' => $id,
                    'client_secret' => $id.'_secret',
                    'amount' => $params['amount'],
                    'currency' => $params['currency'],
                    'status' => 'requires_payment_method',
                ]);
            }
        };

        $this->app->instance(StripePaymentIntentGateway::class, $gateway);

        $response = $this->retry($token, $order, 'idem-lock-proof-1');
        $response->assertStatus(201);

        $lockingQueryFound = collect($queryLog)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'orders') && str_contains(strtolower($sql), 'for update')
        );
        $this->assertTrue($lockingQueryFound, 'Expected a SELECT ... FOR UPDATE query against orders during retry.');

        $this->assertTrue(
            $gateway->orderLockQuerySeenBeforeCall,
            'Expected the orders FOR UPDATE lock to already have been acquired before the Stripe call.'
        );
        $this->assertNotNull($gateway->transactionLevelDuringCall);
        $this->assertGreaterThan(
            0,
            $gateway->transactionLevelDuringCall,
            'Expected an open DB transaction (the held order lock) to still be active at the moment of the Stripe call.'
        );
    }

    /**
     * M-1 (2/2): forces a genuine failure *inside* the locked sequence,
     * after the Payment row has already been inserted and the inventory
     * row has already been decremented in memory within
     * InventoryAdjustmentService::adjust()'s own nested transaction, to
     * prove the entire retry attempt rolls back together — not just that
     * validation can reject a request beforehand. Mirrors
     * StripeWebhookTest::test_forced_failure_during_processing_rolls_back_everything's
     * established forced-failure pattern (an Eloquent `saving` listener),
     * applied to InventoryTransaction instead of Order since that is the
     * last row written in PaymentRetryService's own sequence.
     */
    public function test_forced_failure_after_payment_insert_rolls_back_the_entire_retry_attempt(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        $order = $this->orderReadyForRetry($store, $customer, $variant, 2, 20.00);
        $token = $this->customerToken($customer);

        InventoryTransaction::saving(function () {
            throw new RuntimeException('Simulated failure to verify retry transaction rollback.');
        });

        try {
            $response = $this->retry($token, $order, 'idem-forced-fail-1');

            $response->assertStatus(500);

            // Stripe was already called before the forced failure.
            $this->assertCount(1, $fake->calls);

            // No partial Payment row for this retry attempt — only the
            // original (terminal) Payment from orderReadyForRetry() remains.
            $this->assertDatabaseCount('payments', 1);
            $this->assertDatabaseMissing('payments', ['idempotency_key' => 'idem-forced-fail-1']);

            // No partial inventory claim/decrement: quantity_on_hand is
            // back to its pre-retry level, and exactly one 'checkout'
            // reason row exists — the original attempt's — with none added
            // by this forced-failure retry attempt.
            $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 10]);
            $this->assertSame(
                1,
                InventoryTransaction::where('product_variant_id', $variant->id)->where('reason', 'checkout')->count()
            );

            // The Order remains in its correct, unaffected pre-retry state
            // — this was an unexpected failure, not a business rejection,
            // so unlike insufficient inventory it must NOT cancel the order.
            $order->refresh();
            $this->assertSame(OrderStatus::Pending, $order->status);
        } finally {
            InventoryTransaction::flushEventListeners();
        }
    }

    /**
     * M-2: analogous to CheckoutOrderCreationServiceTest::
     * test_second_line_items_failure_rolls_back_the_first_line_items_claim_too,
     * applied to a retry instead of a fresh checkout. Two order items on
     * one order; the first variant has enough stock to satisfy its claim,
     * the second does not — proving the first item's already-successful
     * claim (and the new Payment row) are rolled back together with the
     * second item's failure, and that the order is left cancelled rather
     * than in some partially-claimed intermediate state.
     */
    public function test_second_items_insufficient_inventory_rolls_back_the_first_items_claim_and_the_payment(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $variantA = $this->activeVariantWithStock($store, 10, 10.00); // enough for its claim
        $variantB = $this->activeVariantWithStock($store, 1, 10.00);  // NOT enough for its claim

        $order = Order::factory()->forStore($store)->create();
        $order->customer_id = $customer->id;
        $order->customer_name = $customer->name;
        $order->customer_email = $customer->email;
        $order->subtotal = 100.00;
        $order->total = 100.00;
        $order->save();

        $itemA = OrderItem::factory()->forOrder($order)->create();
        $itemA->product_id = $variantA->product_id;
        $itemA->product_variant_id = $variantA->id;
        $itemA->unit_price = 10.00;
        $itemA->quantity = 5;
        $itemA->line_total = 50.00;
        $itemA->save();

        $itemB = OrderItem::factory()->forOrder($order)->create();
        $itemB->product_id = $variantB->product_id;
        $itemB->product_variant_id = $variantB->id;
        $itemB->unit_price = 10.00;
        $itemB->quantity = 5;
        $itemB->line_total = 50.00;
        $itemB->save();

        // One terminal Failed Payment, exactly as a real prior checkout
        // attempt would have left behind — the retry eligibility
        // precondition, same as orderReadyForRetry() sets up for the
        // single-item case.
        $originalPayment = Payment::factory()->forOrder($order)->create();
        $originalPayment->status = PaymentStatus::Failed;
        $originalPayment->failure_reason = 'card_declined';
        $originalPayment->save();

        $token = $this->customerToken($customer);

        $response = $this->retry($token, $order, 'idem-multi-item-fail-1');

        $response->assertStatus(422);
        $this->assertCount(1, $fake->calls); // Stripe was already called before the second claim failed

        // No Payment row persisted for this retry attempt.
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseMissing('payments', ['idempotency_key' => 'idem-multi-item-fail-1']);

        // Item A's claim (which succeeded first, inside the same nested
        // transaction as item B's failed claim) must be rolled back too —
        // both variants' stock levels are exactly as they were before
        // this retry attempt.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variantA->id, 'quantity_on_hand' => 10]);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variantB->id, 'quantity_on_hand' => 1]);
        $this->assertDatabaseMissing('inventory_transactions', ['product_variant_id' => $variantA->id, 'reason' => 'checkout']);
        $this->assertDatabaseMissing('inventory_transactions', ['product_variant_id' => $variantB->id, 'reason' => 'checkout']);

        // The order is left cancelled (the correct terminal state for a
        // genuine insufficient-inventory rejection), not in any partially
        // claimed or ambiguous intermediate state.
        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('item_no_longer_available', $order->status_reason);
    }
}
