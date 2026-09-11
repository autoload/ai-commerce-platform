<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\RefundStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Store;
use App\Support\SalesClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * order_count/total_spent semantics, per the approved Phase 9C design plus
 * the Analytics-driven cross-module consistency fix: order_count counts
 * ALL of a customer's orders regardless of status (unchanged). total_spent
 * is now Net Sales per customer (Gross Sales − Sales Refunds, via
 * App\Support\SalesClassification's GROSS_SALE_STATUSES = {Paid,
 * Processing, Shipped, Completed, Refunded}) rather than the original
 * "every status except pending/cancelled" sum, which incorrectly kept a
 * fully-refunded order's amount as spend. G3-B/9E-4 compensation refunds
 * never reduce total_spent — their order is always Cancelled, never in
 * GROSS_SALE_STATUSES, so they're structurally excluded from the
 * sales_refunds subquery without ever checking status_reason.
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

    /**
     * No RefundFactory exists (Refund has no HasFactory — see Refund.php),
     * so a succeeded Refund row is built directly here, matching the
     * "smallest footprint" approach for this narrowly-scoped fix.
     */
    private function createSucceededRefund(Order $order, float $amount): Refund
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

        return $refund;
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

    // ---- Net Sales (Gross Sales - Sales Refunds) --------------------------

    public function test_total_spent_nets_out_a_fully_refunded_order(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $order = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($order, OrderStatus::Refunded, 100.00);
        $this->createSucceededRefund($order, 100.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.order_count', 1)
            ->assertJsonPath('data.total_spent', '0.00');
    }

    /**
     * The G3-B compensation case: a Cancelled order whose payment
     * nonetheless later succeeded and was refunded. This order never
     * contributed to Gross Sales (it's Cancelled, not in
     * SalesClassification::GROSS_SALE_STATUSES), so its refund must not
     * reduce a genuine, separate sale's total_spent.
     */
    public function test_total_spent_is_unaffected_by_a_g3b_compensation_refund(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $genuineSale = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($genuineSale, OrderStatus::Paid, 100.00);

        $compensated = Order::factory()->forCustomer($customer)->create();
        $compensated->status = OrderStatus::Cancelled;
        $compensated->status_reason = 'payment_refunded_after_closure';
        $compensated->total = 50.00;
        $compensated->save();
        $this->createSucceededRefund($compensated, 50.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.total_spent', '100.00');
    }

    /**
     * Same shape as the G3-B test above, for the 9E-4 expiry-sweep
     * compensation case — a separate, distinct status_reason literal, same
     * exclusion mechanism (order is Cancelled, never in
     * GROSS_SALE_STATUSES).
     */
    public function test_total_spent_is_unaffected_by_a_9e4_compensation_refund(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $genuineSale = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($genuineSale, OrderStatus::Paid, 100.00);

        $compensated = Order::factory()->forCustomer($customer)->create();
        $compensated->status = OrderStatus::Cancelled;
        $compensated->status_reason = 'payment_refunded_after_expiry_cancellation';
        $compensated->total = 50.00;
        $compensated->save();
        $this->createSucceededRefund($compensated, 50.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.total_spent', '100.00');
    }

    /**
     * Regression proof that ordinary cancellation (no refund involved at
     * all) is untouched by this change — both the merchant-cancellation
     * and expiry-sweep-cancellation shapes.
     */
    public function test_total_spent_still_excludes_cancelled_orders_with_no_refund(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $merchantCancelled = Order::factory()->forCustomer($customer)->create();
        $merchantCancelled->status = OrderStatus::Cancelled;
        $merchantCancelled->status_reason = null;
        $merchantCancelled->total = 40.00;
        $merchantCancelled->save();

        $expirySweepCancelled = Order::factory()->forCustomer($customer)->create();
        $expirySweepCancelled->status = OrderStatus::Cancelled;
        $expirySweepCancelled->status_reason = 'expired';
        $expirySweepCancelled->total = 60.00;
        $expirySweepCancelled->save();

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.total_spent', '0.00');
    }

    /**
     * No Analytics customer-revenue endpoint exists yet — this proves
     * CustomerController's total_spent matches an independent computation
     * built directly from SalesClassification, standing in for what a
     * future AnalyticsService must also produce. Once Analytics' own
     * customer-revenue endpoint is implemented, a separate, real
     * cross-endpoint test (total_spent from /customers/{customer} ==
     * revenue from /analytics/customers) must be added — this test does
     * not replace that future one.
     */
    public function test_total_spent_matches_an_independently_computed_net_sales_calculation(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $genuineSale = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($genuineSale, OrderStatus::Paid, 100.00);

        $refundedSale = Order::factory()->forCustomer($customer)->create();
        $this->setOrderStatusAndTotal($refundedSale, OrderStatus::Refunded, 30.00);
        $this->createSucceededRefund($refundedSale, 30.00);

        $compensated = Order::factory()->forCustomer($customer)->create();
        $compensated->status = OrderStatus::Cancelled;
        $compensated->status_reason = 'payment_refunded_after_closure';
        $compensated->total = 20.00;
        $compensated->save();
        $this->createSucceededRefund($compensated, 20.00);

        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");
        $response->assertOk();

        $expectedGrossSales = (float) SalesClassification::scopeGrossSaleOrders(
            Order::where('customer_id', $customer->id)
        )->sum('total');

        $expectedSalesRefunds = (float) Refund::where('status', RefundStatus::Succeeded)
            ->whereHas('order', function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
                SalesClassification::scopeGrossSaleOrders($q);
            })
            ->sum('amount');

        $expectedNetSales = number_format($expectedGrossSales - $expectedSalesRefunds, 2, '.', '');

        $this->assertSame('100.00', $expectedNetSales); // 100 (genuine) + (30 refunded - 30 its own refund) + 0 (compensation, excluded)
        $response->assertJsonPath('data.total_spent', $expectedNetSales);
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
