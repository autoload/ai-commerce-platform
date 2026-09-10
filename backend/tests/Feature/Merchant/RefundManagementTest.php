<?php

namespace Tests\Feature\Merchant;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Store;
use App\Services\CheckoutOrderCreationService;
use App\Services\InventoryAdjustmentService;
use App\Services\StripeRefundGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ErrorObject;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\Doubles\FakeRefundGateway;
use Tests\TestCase;

/**
 * Phase 9D — POST /api/stores/{store}/orders/{order}/refund. Fixtures
 * drive the real CheckoutOrderCreationService (unmodified) to produce a
 * genuine claimed-inventory Order/Payment, then fast-forward to the paid
 * state a refund test needs — the same discipline StripeWebhookTest's
 * checkoutFixture() and PaymentRetryTest's fixtures already establish.
 */
class RefundManagementTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private function fakeGateway(): FakeRefundGateway
    {
        $fake = new FakeRefundGateway;
        $this->app->instance(StripeRefundGateway::class, $fake);

        return $fake;
    }

    /**
     * @return array{order: Order, payment: Payment, variant: ProductVariant, store: Store}
     */
    private function paidFixture(Organization $org, Store $store, int $stock = 10, float $price = 20.00, int $quantity = 2): array
    {
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

        $payment = $order->payments->first();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();

        $order->status = OrderStatus::Paid;
        $order->paid_at = now();
        $order->save();

        return ['order' => $order->fresh(), 'payment' => $payment->fresh(), 'variant' => $variant, 'store' => $store];
    }

    // ---- Success paths --------------------------------------------------

    public function test_owner_can_refund_a_paid_order(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store, quantity: 3, price: 15.00);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1', 'reason' => 'Customer changed their mind']
        );

        $response->assertCreated()
            ->assertJsonPath('data.order_id', $fixture['order']->id)
            ->assertJsonPath('data.payment_id', $fixture['payment']->id)
            ->assertJsonPath('data.amount', $fixture['payment']->amount)
            ->assertJsonPath('data.reason', 'Customer changed their mind')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('refunds', [
            'order_id' => $fixture['order']->id,
            'payment_id' => $fixture['payment']->id,
            'status' => 'pending',
        ]);

        // Order/inventory are NOT touched synchronously — the webhook is
        // the sole authority for succeeded/failed reconciliation.
        $this->assertSame(OrderStatus::Paid, $fixture['order']->fresh()->status);

        $this->assertCount(1, $fake->calls);
        $this->assertSame($fixture['payment']->stripe_payment_intent_id, $fake->calls[0]['params']['payment_intent']);
        $this->assertSame((int) round($fixture['payment']->amount * 100), $fake->calls[0]['params']['amount']);
        $this->assertSame(
            hash('sha256', "refund:{$fixture['payment']->id}:refund-key-1"),
            $fake->calls[0]['idempotency_key']
        );
    }

    public function test_store_admin_can_refund_a_paid_order(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $fixture = $this->paidFixture($org, $store);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertCreated();
    }

    public function test_refund_reason_is_optional(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertCreated()->assertJsonPath('data.reason', null);
    }

    // ---- Authorization (RBAC) -------------------------------------------

    public function test_staff_cannot_refund_an_order(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $fixture = $this->paidFixture($org, $store);
        $token = $staff->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('refunds', 0);
    }

    // ---- Eligibility ------------------------------------------------------

    public function test_pending_order_cannot_be_refunded(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        // stays pending (factory default)
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_already_refunded_order_cannot_be_refunded_again(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $order = $fixture['order'];
        $order->status = OrderStatus::Refunded;
        $order->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
    }

    public function test_order_with_no_succeeded_payment_cannot_be_refunded(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Paid;
        $order->save();
        // No Payment row at all for this order.
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
    }

    public function test_order_whose_payment_is_not_succeeded_cannot_be_refunded(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Paid;
        $order->save();
        $payment = Payment::factory()->forOrder($order)->create();
        $payment->status = PaymentStatus::Failed;
        $payment->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
    }

    // ---- Phase 9E-2 (G3-B) eligibility --------------------------------------

    /**
     * Regression proof that RefundService::isRefundableOrderState()'s
     * extraction (Phase 9E-2) didn't change eligibility for the three
     * REFUNDABLE_ORDER_STATUSES members not otherwise exercised by
     * test_owner_can_refund_a_paid_order() above.
     *
     * @return array{OrderStatus}[]
     */
    public static function otherRefundableStatusesProvider(): array
    {
        return [
            'processing' => [OrderStatus::Processing],
            'shipped' => [OrderStatus::Shipped],
            'completed' => [OrderStatus::Completed],
        ];
    }

    #[DataProvider('otherRefundableStatusesProvider')]
    public function test_other_refundable_statuses_remain_refundable(OrderStatus $status): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $order->status = $status;
        $order->save();
        $payment = Payment::factory()->forOrder($order)->create();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-'.$status->value]
        );

        $response->assertCreated();
    }

    public function test_ordinary_cancelled_order_cannot_be_refunded(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'merchant_cancelled';
        $order->save();
        $payment = Payment::factory()->forOrder($order)->create();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('refunds', 0);
    }

    /**
     * The one narrow G3-B carve-out: a Cancelled order whose status_reason
     * is exactly the G3-A alarm value, with a Succeeded Payment, is
     * eligible -- not because it's Cancelled, but because of the exact
     * reason string (proven distinct from an ordinary Cancelled order by
     * the test immediately above, and from a differently-reasoned
     * Cancelled order by the next test below).
     */
    public function test_cancelled_order_with_closure_alarm_is_refundable(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'payment_succeeded_after_closure';
        $order->save();
        $payment = Payment::factory()->forOrder($order)->create();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertCreated();
        $this->assertDatabaseHas('refunds', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        // The order itself must not have been touched by RefundService --
        // it stays exactly as it was; only the (separate, webhook-driven)
        // successful-refund transition resolves status_reason.
        $order->refresh();
        $this->assertSame('cancelled', $order->status->value);
        $this->assertSame('payment_succeeded_after_closure', $order->status_reason);
    }

    /**
     * A Cancelled order with a Succeeded Payment but a DIFFERENT
     * status_reason (e.g. the expiry sweep's own value) must still be
     * rejected -- proves this is not "any Cancelled order with a
     * succeeded payment is refundable," only the exact alarm string.
     */
    public function test_cancelled_order_with_different_status_reason_cannot_be_refunded(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'expired';
        $order->save();
        $payment = Payment::factory()->forOrder($order)->create();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cancelled_order_with_closure_alarm_but_no_succeeded_payment_cannot_be_refunded(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Cancelled;
        $order->status_reason = 'payment_succeeded_after_closure';
        $order->save();
        // No Payment row at all -- the order-status branch now passes,
        // but the separate, unmodified "succeeded payment exists" check
        // must still reject it.
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('refunds', 0);
    }

    // ---- Idempotency / concurrency ----------------------------------------

    public function test_existing_active_refund_is_rejected_with_409(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $first = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );
        $first->assertCreated();

        $second = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-2']
        );

        $second->assertStatus(409);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertCount(1, $fake->calls);
    }

    public function test_same_idempotency_key_replayed_after_first_commit_does_not_duplicate_the_stripe_call(): void
    {
        // Per the approved Phase 9D design (no refunds.idempotency_key
        // column), a second request — even with the identical key — is
        // rejected the same way as any other request once an active
        // Refund already exists: the orders row lock is what prevents a
        // second Stripe call, not local key comparison. The invariant
        // under test is "no duplicate Stripe call / no duplicate Refund
        // row", not that the second response is a 200.
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $first = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'same-key']
        );
        $first->assertCreated();

        $second = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'same-key']
        );

        $second->assertStatus(409);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertCount(1, $fake->calls);
    }

    public function test_different_concurrent_refund_requests_result_in_exactly_one_stripe_call(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        // Simulates two requests serialized by the orders row lock — the
        // second only ever runs after the first's transaction (including
        // its Stripe call and Refund insert) has fully committed.
        $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'key-a']
        )->assertCreated();

        $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'key-b']
        )->assertStatus(409);

        $this->assertCount(1, $fake->calls);
        $this->assertDatabaseCount('refunds', 1);
    }

    // ---- Stripe error mapping -----------------------------------------------

    public function test_generic_stripe_api_failure_is_mapped_to_502(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $fake->willThrow(ApiConnectionException::factory('Could not connect to Stripe over the internal test network.'));

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(502);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_stripe_idempotency_conflict_is_mapped_to_409(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $fake->willThrow(IdempotencyException::factory(
            'Keys for idempotent requests can only be used with the same parameters they were first used with, or if there is an existing request in progress.',
            400,
            null,
            null,
            null,
            ErrorObject::CODE_IDEMPOTENCY_KEY_IN_USE,
        ));

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(409);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_stripe_already_refunded_response_is_mapped_to_a_clean_422(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $fake->willThrow(InvalidRequestException::factory(
            'Charge ch_test has already been refunded.',
            400,
        ));

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('refunds', 0);
    }

    // ---- Resource shape -----------------------------------------------------

    public function test_refund_resource_exposes_only_approved_fields(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        );

        $response->assertCreated();
        $json = $response->json('data');
        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('order_id', $json);
        $this->assertArrayHasKey('payment_id', $json);
        $this->assertArrayHasKey('amount', $json);
        $this->assertArrayHasKey('reason', $json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('created_at', $json);
        $this->assertArrayNotHasKey('stripe_refund_id', $json);
        $this->assertArrayNotHasKey('initiated_by_user_id', $json);
        $this->assertArrayNotHasKey('organization_id', $json);
        $this->assertArrayNotHasKey('store_id', $json);
    }

    public function test_order_detail_includes_refunds_once_one_exists(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        )->assertCreated();

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$fixture['order']->id}");

        $response->assertOk()->assertJsonCount(1, 'data.refunds');
        $this->assertSame('pending', $response->json('data.refunds.0.status'));
    }

    public function test_order_list_does_not_include_refunds(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        )->assertCreated();

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders");

        $response->assertOk();
        $this->assertArrayNotHasKey('refunds', $response->json('data.0'));
    }

    // ---- Authentication --------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);

        $this->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        )->assertStatus(401);
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $fixture = $this->paidFixture($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$fixture['order']->id}/refund",
            []
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);
    }
}
