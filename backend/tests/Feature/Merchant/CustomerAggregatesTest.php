<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * order_count/total_spent semantics, per the approved Phase 9C design:
 * order_count counts ALL of a customer's orders regardless of status;
 * total_spent sums orders.total for every status except pending/cancelled
 * (refunded currently counts too — no merchant refund workflow exists yet
 * to make this moot in any other way). No refund-aware accounting is
 * tested here — explicitly out of scope.
 */
class CustomerAggregatesTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private function setOrderStatusAndTotal(Order $order, OrderStatus $status, float $total): void
    {
        $order->status = $status;
        $order->total = $total;
        $order->save();
    }

    public function test_order_count_counts_all_orders_regardless_of_status(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $paid = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($paid, OrderStatus::Paid, 40.00);

        $pending = Order::factory()->forCustomer($customer)->create();
        // stays pending (the factory's default status)

        $cancelled = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($cancelled, OrderStatus::Cancelled, 888.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()->assertJsonPath('data.order_count', 3);
    }

    public function test_total_spent_excludes_pending_orders(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $paid = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($paid, OrderStatus::Paid, 40.00);

        $pending = Order::factory()->forCustomer($customer)->create();
        $pending->total = 999.00;
        $pending->save();
        // status left at its pending default

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()->assertJsonPath('data.total_spent', '40.00');
    }

    public function test_total_spent_excludes_cancelled_orders(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $paid = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($paid, OrderStatus::Paid, 40.00);

        $cancelled = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($cancelled, OrderStatus::Cancelled, 888.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()->assertJsonPath('data.total_spent', '40.00');
    }

    public function test_qualifying_order_totals_are_summed_correctly_across_statuses(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $paid = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($paid, OrderStatus::Paid, 40.00);

        $processing = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($processing, OrderStatus::Processing, 30.50);

        $shipped = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($shipped, OrderStatus::Shipped, 10.25);

        $completed = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($completed, OrderStatus::Completed, 5.00);

        // Present but must not count toward the sum.
        $pending = Order::factory()->forCustomer($customer)->create();
        $pending->total = 999.00;
        $pending->save();

        $cancelled = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($cancelled, OrderStatus::Cancelled, 888.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.order_count', 6)
            ->assertJsonPath('data.total_spent', '85.75');
    }

    public function test_customer_with_zero_orders_has_zero_order_count_and_zero_total_spent(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.order_count', 0)
            ->assertJsonPath('data.total_spent', '0.00');
    }

    public function test_customer_with_only_pending_and_cancelled_orders_has_positive_count_but_zero_total_spent(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $pending = Order::factory()->forCustomer($customer)->create();
        $pending->total = 999.00;
        $pending->save();

        $cancelled = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($cancelled, OrderStatus::Cancelled, 888.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.total_spent', '0.00');
    }

    public function test_aggregates_are_correct_per_row_in_the_list_endpoint(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();

        $customerWithOrders = Customer::factory()->forStore($store)->create(['name' => 'Has Orders']);
        $paid = Order::factory()->forCustomer($customerWithOrders)->create();
        $this->setOrderStatusAndTotal($paid, OrderStatus::Paid, 40.00);
        $pending = Order::factory()->forCustomer($customerWithOrders)->create();
        $pending->total = 999.00;
        $pending->save();

        $customerWithoutOrders = Customer::factory()->forStore($store)->create(['name' => 'No Orders']);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers");

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('name');
        $this->assertSame(2, $rows['Has Orders']['order_count']);
        $this->assertSame('40.00', $rows['Has Orders']['total_spent']);
        $this->assertSame(0, $rows['No Orders']['order_count']);
        $this->assertSame('0.00', $rows['No Orders']['total_spent']);
    }

    public function test_list_query_count_does_not_scale_with_the_number_of_customers(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();

        $firstCustomer = Customer::factory()->forStore($store)->create();
        $order = Order::factory()->forCustomer($firstCustomer)->create();
        $this->setOrderStatusAndTotal($order, OrderStatus::Paid, 40.00);

        // A fresh token per measurement, not one reused token — Sanctum's
        // guard caches the resolved user on the Guard instance for the
        // rest of the test process, AND a reused token's last_used_at
        // update can become a no-op save() (non-dirty) if both calls land
        // within the same wall-clock second. Both effects would shave a
        // few queries off the SECOND call regardless of dataset size,
        // producing a false-negative "fewer queries" result unrelated to
        // the customers endpoint. A brand-new token's last_used_at always
        // starts null, so authenticating with it is always a genuine
        // dirty UPDATE — the same fix this project's auth tests already
        // use for the analogous "two authenticated requests in one test
        // method" guard-caching gotcha, applied here via a fresh token
        // instead of Auth::forgetGuards() alone (which was not, by
        // itself, enough to make last_used_at's UPDATE deterministic).
        $firstToken = $owner->createToken('t1')->plainTextToken;

        DB::enableQueryLog();
        $this->withToken($firstToken)->getJson("/api/stores/{$store->id}/customers")->assertOk();
        $oneCustomerQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        Auth::forgetGuards();

        // Logging is disabled while creating fixtures — otherwise the
        // fixture-creation queries themselves would inflate this count and
        // produce a false N+1 failure.
        for ($i = 0; $i < 4; $i++) {
            $extraCustomer = Customer::factory()->forStore($store)->create();
            $extraOrder = Order::factory()->forCustomer($extraCustomer)->create();
            $this->setOrderStatusAndTotal($extraOrder, OrderStatus::Paid, 10.00);
        }

        $secondToken = $owner->createToken('t2')->plainTextToken;

        DB::enableQueryLog();
        $this->withToken($secondToken)->getJson("/api/stores/{$store->id}/customers")->assertOk();
        $fiveCustomerQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $oneCustomerQueryCount,
            $fiveCustomerQueryCount,
            'Query count should not scale with the number of customers (N+1 regression) — order_count/total_spent must be single database-side aggregates.'
        );
    }
}
