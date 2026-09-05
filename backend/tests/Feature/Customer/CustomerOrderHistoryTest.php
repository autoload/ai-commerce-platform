<?php

namespace Tests\Feature\Customer;

use App\Enums\OrganizationStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 — GET /api/customers/orders, GET /api/customers/orders/{order}.
 * Mirrors Merchant\OrderControllerTest's coverage style, scoped to
 * ownership (customer_id) rather than RBAC — there is only one "role" on
 * the customer side, so isolation is proven by scoped-query tests instead
 * of a policy matrix.
 */
class CustomerOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function activeStore(): Store
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();

        return Store::factory()->forOrganization($org)->create();
    }

    private function orderForCustomer(Customer $customer): Order
    {
        $order = Order::factory()->forStore($customer->store)->create();
        $order->customer_id = $customer->id;
        $order->customer_name = $customer->name;
        $order->customer_email = $customer->email;
        $order->save();

        return $order->fresh();
    }

    public function test_authenticated_customer_can_list_their_own_orders(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $this->orderForCustomer($customer);
        $this->orderForCustomer($customer);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/orders');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_unauthenticated_request_is_rejected_on_every_order_route(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);

        $this->getJson('/api/customers/orders')->assertStatus(401);
        $this->getJson("/api/customers/orders/{$order->id}")->assertStatus(401);
    }

    public function test_authenticated_customer_can_view_their_own_order(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/customers/orders/{$order->id}");

        $response->assertOk()->assertJsonPath('data.id', $order->id);
    }

    public function test_another_customers_order_returns_404(): void
    {
        $store = $this->activeStore();
        $customerA = Customer::factory()->forStore($store)->create();
        $customerB = Customer::factory()->forStore($store)->create();
        $orderB = $this->orderForCustomer($customerB);
        $token = $customerA->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/customers/orders/{$orderB->id}")->assertStatus(404);
    }

    public function test_an_order_from_a_different_store_returns_404(): void
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $customerA = Customer::factory()->forStore($storeA)->create();
        $customerB = Customer::factory()->forStore($storeB)->create();
        $orderB = $this->orderForCustomer($customerB);
        $token = $customerA->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/customers/orders/{$orderB->id}")->assertStatus(404);
    }

    public function test_an_order_from_a_different_organization_returns_404(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $customerA = Customer::factory()->forStore($storeA)->create();
        $customerB = Customer::factory()->forStore($storeB)->create();
        $orderB = $this->orderForCustomer($customerB);
        $token = $customerA->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/customers/orders/{$orderB->id}")->assertStatus(404);
    }

    public function test_merchant_token_cannot_access_customer_order_routes(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);
        $merchant = User::factory()->create();
        $token = $merchant->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/customers/orders')->assertStatus(401);
        $this->withToken($token)->getJson("/api/customers/orders/{$order->id}")->assertStatus(401);
    }

    public function test_nonexistent_order_returns_404(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/customers/orders/999999')->assertStatus(404);
    }

    public function test_pagination_defaults_to_fifteen_per_page(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        for ($i = 0; $i < 16; $i++) {
            $this->orderForCustomer($customer);
        }
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/orders');

        $response->assertOk();
        $this->assertCount(15, $response->json('data'));
        $this->assertSame(16, $response->json('meta.total'));
    }

    public function test_empty_order_history_returns_an_empty_list(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/orders');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_orders_are_sorted_by_created_at_descending(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $older = $this->orderForCustomer($customer);
        $older->created_at = now()->subDay();
        $older->save();
        $newer = $this->orderForCustomer($customer);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/orders');

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_list_response_does_not_include_items_or_shipping_address(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);
        OrderItem::factory()->forOrder($order)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/orders');

        $response->assertOk();
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
        $this->assertArrayNotHasKey('shipping_address', $response->json('data.0'));
    }

    public function test_order_detail_includes_items_and_shipping_address(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);
        $item = OrderItem::factory()->forOrder($order)->create();
        $item->product_name = 'Blue Mug';
        $item->sku = 'MUG-BLUE';
        $item->save();
        $address = OrderAddress::factory()->forOrder($order)->create();
        $address->recipient_name = 'Jane Doe';
        $address->save();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/customers/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('data.items.0.product_name', 'Blue Mug')
            ->assertJsonPath('data.items.0.sku', 'MUG-BLUE')
            ->assertJsonPath('data.shipping_address.recipient_name', 'Jane Doe');
    }

    public function test_order_detail_does_not_expose_internal_or_admin_only_fields(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);
        Payment::factory()->forOrder($order)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/customers/orders/{$order->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayNotHasKey('organization_id', $data);
        $this->assertArrayNotHasKey('customer_id', $data);
        $this->assertArrayNotHasKey('store_id', $data);
        $this->assertArrayNotHasKey('stripe_payment_intent_id', $data);
        $this->assertArrayNotHasKey('client_secret', $data);
        $this->assertArrayNotHasKey('payments', $data);
        $this->assertArrayNotHasKey('refunds', $data);
        $this->assertArrayNotHasKey('inventory_transactions', $data);
    }

    /**
     * Proves payment_status is derived from the MOST RECENT Payment row,
     * not merely "any" Payment or the first one created — two Payments are
     * created for the same order (an earlier failed attempt, then a later
     * successful retry), with created_at set explicitly to guarantee
     * ordering rather than relying on real-clock timing.
     */
    public function test_derived_payment_status_reflects_the_latest_payment_attempt(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);

        $earlier = Payment::factory()->forOrder($order)->create();
        $earlier->status = PaymentStatus::Failed;
        $earlier->created_at = now()->subMinutes(10);
        $earlier->save();

        $later = Payment::factory()->forOrder($order)->create();
        $later->status = PaymentStatus::Succeeded;
        $later->created_at = now();
        $later->save();

        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/customers/orders/{$order->id}");

        $response->assertOk()->assertJsonPath('data.payment_status', 'succeeded');
    }

    public function test_list_also_includes_derived_payment_status(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $order = $this->orderForCustomer($customer);
        $payment = Payment::factory()->forOrder($order)->create();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/orders');

        $response->assertOk()->assertJsonPath('data.0.payment_status', 'succeeded');
    }
}
