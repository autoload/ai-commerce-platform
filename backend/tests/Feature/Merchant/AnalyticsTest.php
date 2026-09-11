<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\RefundStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Analytics v1 — formula correctness. Time is frozen (travelTo, the same
 * technique PaymentExpirySweepServiceTest already established) so every
 * preset's [start,end) boundaries are deterministic against hand-placed
 * fixture timestamps, matching AnalyticsDateRangeTest's own computed
 * boundaries for `last_7_days` at this frozen instant:
 *   current  = [2026-09-09T00:00:00Z, 2026-09-16T00:00:00Z)
 *   previous = [2026-09-02T00:00:00Z, 2026-09-09T00:00:00Z)
 */
class AnalyticsTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private const FROZEN_NOW = '2026-09-15T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    private function tokenFor(OrganizationRole $role, Store $store): array
    {
        $user = $this->memberWithRole($store->organization, $role);
        if ($role !== OrganizationRole::Owner) {
            $this->attachToStore($user, $store);
        }

        return [$user, $user->createToken('t')->plainTextToken];
    }

    private function grossSaleOrder(Store $store, float $total, CarbonImmutable $paidAt, ?Customer $customer = null): Order
    {
        $order = $customer
            ? Order::factory()->forCustomer($customer)->create()
            : Order::factory()->forStore($store)->create();

        $order->status = OrderStatus::Paid;
        $order->total = $total;
        $order->paid_at = $paidAt;
        $order->save();

        return $order;
    }

    private function succeededRefund(Order $order, float $amount, CarbonImmutable $createdAt): Refund
    {
        $refund = new Refund;
        $refund->organization_id = $order->organization_id;
        $refund->store_id = $order->store_id;
        $refund->order_id = $order->id;
        $refund->payment_id = Payment::factory()->forOrder($order)->create()->id;
        $refund->stripe_refund_id = 're_test_'.Str::random(16);
        $refund->amount = $amount;
        $refund->status = RefundStatus::Succeeded;
        $refund->save();

        $refund->created_at = $createdAt;
        $refund->save();

        return $refund;
    }

    // ---- Sales summary -------------------------------------------------

    public function test_sales_summary_computes_gross_sales_refunds_net_sales_count_and_aov(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $inRange1 = $this->grossSaleOrder($store, 100.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'));
        $this->grossSaleOrder($store, 50.00, CarbonImmutable::parse('2026-09-12T10:00:00Z'));
        // Outside the current window entirely — must not affect the figures.
        $this->grossSaleOrder($store, 999.00, CarbonImmutable::parse('2026-08-01T10:00:00Z'));

        $this->succeededRefund($inRange1, 30.00, CarbonImmutable::parse('2026-09-11T10:00:00Z'));

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/sales?range=last_7_days");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('150.00', $data['gross_sales']);
        $this->assertSame('30.00', $data['sales_refunds']);
        $this->assertSame('120.00', $data['net_sales']);
        $this->assertSame(2, $data['order_count']);
        $this->assertSame('60.00', $data['aov']);
        $this->assertSame('last_7_days', $data['range']['preset']);
    }

    public function test_aov_is_null_when_there_are_no_gross_sale_orders_in_range(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/sales?range=last_7_days");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('0.00', $data['gross_sales']);
        $this->assertSame(0, $data['order_count']);
        $this->assertNull($data['aov']);
    }

    public function test_sales_growth_is_a_normal_ratio_between_current_and_previous_periods(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        // Current window [Sep9, Sep16): net sales 200.
        $this->grossSaleOrder($store, 200.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'));
        // Previous window [Sep2, Sep9): net sales 100.
        $this->grossSaleOrder($store, 100.00, CarbonImmutable::parse('2026-09-05T10:00:00Z'));

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/sales?range=last_7_days");

        // json_encode() drops the trailing ".0" for a whole-number float
        // (no JSON_PRESERVE_ZERO_FRACTION), so the wire value round-trips
        // as an int here — assertEquals (loose) is correct, not a bug.
        $response->assertOk();
        $this->assertEquals(100.0, $response->json('data.growth_percent'));
    }

    public function test_sales_growth_is_zero_percent_when_both_periods_have_zero_net_sales(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/sales?range=last_7_days");

        $response->assertOk();
        $this->assertEquals(0.0, $response->json('data.growth_percent'));
    }

    public function test_sales_growth_is_null_when_previous_period_is_zero_and_current_is_positive(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $this->grossSaleOrder($store, 50.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'));

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/sales?range=last_7_days");

        $response->assertOk()->assertJsonPath('data.growth_percent', null);
    }

    // ---- Order status breakdown -----------------------------------------

    public function test_order_status_breakdown_is_anchored_to_created_at_not_status_transition_date(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        // Created inside the window, currently Completed — even though a
        // completed order's transition necessarily happened after paid_at,
        // it's counted here because it was CREATED inside the window.
        $inWindow = Order::factory()->forStore($store)->create();
        $inWindow->status = OrderStatus::Completed;
        $inWindow->created_at = CarbonImmutable::parse('2026-09-10T10:00:00Z');
        $inWindow->save();

        // Created before the window — excluded, regardless of current status.
        $beforeWindow = Order::factory()->forStore($store)->create();
        $beforeWindow->status = OrderStatus::Completed;
        $beforeWindow->created_at = CarbonImmutable::parse('2026-08-01T10:00:00Z');
        $beforeWindow->save();

        $cancelled = Order::factory()->forStore($store)->create();
        $cancelled->status = OrderStatus::Cancelled;
        $cancelled->created_at = CarbonImmutable::parse('2026-09-11T10:00:00Z');
        $cancelled->save();

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/orders?range=last_7_days");

        $response->assertOk();
        $counts = $response->json('data.counts');
        $this->assertSame(1, $counts['completed']);
        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(0, $counts['refunded']);
        $this->assertSame(0, $counts['pending']);
    }

    // ---- Product analytics -----------------------------------------------

    public function test_product_revenue_and_quantity_are_aggregated_across_order_items(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $product = Product::factory()->forStore($store)->create(['name' => 'Widget']);

        $orderA = $this->grossSaleOrder($store, 60.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'));
        $itemA = OrderItem::factory()->forOrder($orderA)->create();
        $itemA->product_id = $product->id;
        $itemA->product_name = 'Widget';
        $itemA->unit_price = 10.00;
        $itemA->quantity = 3;
        $itemA->line_total = 30.00;
        $itemA->save();

        $orderB = $this->grossSaleOrder($store, 60.00, CarbonImmutable::parse('2026-09-11T10:00:00Z'));
        $itemB = OrderItem::factory()->forOrder($orderB)->create();
        $itemB->product_id = $product->id;
        $itemB->product_name = 'Widget';
        $itemB->unit_price = 10.00;
        $itemB->quantity = 2;
        $itemB->line_total = 20.00;
        $itemB->save();

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/products?range=last_7_days");

        $response->assertOk();
        $products = $response->json('data.products');
        $this->assertCount(1, $products);
        $this->assertSame($product->id, $products[0]['product_id']);
        $this->assertSame('50.00', $products[0]['revenue']);
        $this->assertSame(5, $products[0]['quantity_sold']);
        $this->assertSame([
            ['date' => '2026-09-10', 'revenue' => '30.00', 'quantity_sold' => 3],
            ['date' => '2026-09-11', 'revenue' => '20.00', 'quantity_sold' => 2],
        ], $products[0]['daily_trend']);
    }

    public function test_deleted_product_items_are_grouped_by_snapshot_name_not_lost(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $order = $this->grossSaleOrder($store, 60.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'));

        $deletedA = OrderItem::factory()->forOrder($order)->create();
        $deletedA->product_id = null;
        $deletedA->product_name = 'Discontinued Gadget';
        $deletedA->unit_price = 15.00;
        $deletedA->quantity = 1;
        $deletedA->line_total = 15.00;
        $deletedA->save();

        $deletedB = OrderItem::factory()->forOrder($order)->create();
        $deletedB->product_id = null;
        $deletedB->product_name = 'Retired Accessory';
        $deletedB->unit_price = 5.00;
        $deletedB->quantity = 1;
        $deletedB->line_total = 5.00;
        $deletedB->save();

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/products?range=last_7_days");

        $response->assertOk();
        $products = collect($response->json('data.products'));
        $this->assertNull($products->firstWhere('product_name', 'Discontinued Gadget')['product_id']);
        $this->assertSame('15.00', $products->firstWhere('product_name', 'Discontinued Gadget')['revenue']);
        $this->assertSame('5.00', $products->firstWhere('product_name', 'Retired Accessory')['revenue']);
    }

    // ---- Customer analytics -----------------------------------------------

    public function test_new_customers_counts_registrations_by_created_at_in_range(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $inWindow = Customer::factory()->forStore($store)->create();
        $inWindow->created_at = CarbonImmutable::parse('2026-09-10T10:00:00Z');
        $inWindow->save();

        $beforeWindow = Customer::factory()->forStore($store)->create();
        $beforeWindow->created_at = CarbonImmutable::parse('2026-08-01T10:00:00Z');
        $beforeWindow->save();

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/customers?range=last_7_days");

        $response->assertOk()->assertJsonPath('data.new_customers', 1);
    }

    public function test_a_customer_with_a_prior_order_and_a_new_order_in_period_is_returning(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $customer = Customer::factory()->forStore($store)->create();
        $this->grossSaleOrder($store, 40.00, CarbonImmutable::parse('2026-08-01T10:00:00Z'), $customer); // before window
        $this->grossSaleOrder($store, 40.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'), $customer); // within window

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/customers?range=last_7_days");

        $response->assertOk()->assertJsonPath('data.returning_customers', 1);
    }

    public function test_a_customer_whose_first_and_second_purchase_both_fall_in_the_current_period_is_not_returning(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $customer = Customer::factory()->forStore($store)->create();
        $this->grossSaleOrder($store, 40.00, CarbonImmutable::parse('2026-09-09T10:00:00Z'), $customer);
        $this->grossSaleOrder($store, 40.00, CarbonImmutable::parse('2026-09-12T10:00:00Z'), $customer);

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/customers?range=last_7_days");

        $response->assertOk()
            ->assertJsonPath('data.returning_customers', 0);
    }

    public function test_top_customers_are_ranked_by_period_net_sales(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $bigSpender = Customer::factory()->forStore($store)->create(['name' => 'Big Spender']);
        $this->grossSaleOrder($store, 500.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'), $bigSpender);

        $smallSpender = Customer::factory()->forStore($store)->create(['name' => 'Small Spender']);
        $this->grossSaleOrder($store, 20.00, CarbonImmutable::parse('2026-09-11T10:00:00Z'), $smallSpender);

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/customers?range=last_7_days&limit=10");

        $response->assertOk();
        $top = $response->json('data.top_customers');
        $this->assertSame('Big Spender', $top[0]['name']);
        $this->assertSame('500.00', $top[0]['net_sales']);
        $this->assertSame('Small Spender', $top[1]['name']);
    }

    /**
     * Approved decision #14 — the required cross-module consistency check:
     * Analytics customer revenue must equal CustomerController's total_spent
     * (the pre-existing Phase 9C/Analytics-fix precedent) for the same
     * customer, using the identical Net Sales definition. A date range wide
     * enough to cover the full fixture history stands in for "all time"
     * without Analytics needing a dedicated all-time mode.
     */
    public function test_analytics_customer_net_sales_matches_customer_management_total_spent(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        [, $token] = $this->tokenFor(OrganizationRole::Owner, $store);

        $customer = Customer::factory()->forStore($store)->create();
        $paidOrder = $this->grossSaleOrder($store, 300.00, CarbonImmutable::parse('2026-09-10T10:00:00Z'), $customer);
        $this->succeededRefund($paidOrder, 50.00, CarbonImmutable::parse('2026-09-11T10:00:00Z'));

        $customerResponse = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");
        $customerResponse->assertOk();
        $totalSpent = $customerResponse->json('data.total_spent');

        // Wide enough to contain every fixture timestamp above.
        $analyticsResponse = $this->withToken($token)->getJson(
            "/api/stores/{$store->id}/analytics/customers?range=last_30_days&limit=10"
        );
        $analyticsResponse->assertOk();
        $topCustomer = collect($analyticsResponse->json('data.top_customers'))->firstWhere('customer_id', $customer->id);

        $this->assertSame($totalSpent, $topCustomer['net_sales']);
        $this->assertSame('250.00', $totalSpent);
    }

    // ---- Authorization smoke check (full RBAC matrix lives in AnalyticsTenantIsolationTest) --

    public function test_staff_is_denied_access_to_every_analytics_endpoint(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $this->attachToStore($staff, $store);
        $token = $staff->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/sales")->assertStatus(403);
        $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/orders")->assertStatus(403);
        $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/products")->assertStatus(403);
        $this->withToken($token)->getJson("/api/stores/{$store->id}/analytics/customers")->assertStatus(403);
    }
}
