<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\Store;
use App\Support\Analytics\SalesSummary;
use App\Support\AnalyticsDateRange;
use App\Support\SalesClassification;
use App\Support\SalesRefundClassification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single authoritative implementation behind every Analytics REST
 * endpoint — and, per the approved design, the same implementation future
 * AI tools (getSales/getProducts/getCustomers/...) must consume unmodified
 * rather than duplicating SQL/business definitions (system-architecture.md
 * §8). Owns aggregation/orchestration only: classification predicates live
 * in SalesClassification (Gross Sale) and SalesRefundClassification (Sales
 * Refund); date-range resolution lives in AnalyticsDateRange. No HTTP
 * concerns, no response shaping — that's the Controller's job.
 *
 * All period figures are event-date, cash-basis: Gross Sales/Order Count/
 * AOV are anchored to orders.paid_at (the moment an order became a sale);
 * Sales Refunds are anchored to refunds.created_at (the moment the refund
 * happened) — a period's Net Sales nets that period's own refund events,
 * not refunds-of-sales-that-originated-in-that-period specifically.
 */
class AnalyticsService
{
    public function getSalesSummary(Store $store, AnalyticsDateRange $range): SalesSummary
    {
        [$grossSales, $orderCount] = $this->grossSalesAndCount($store, $range->start, $range->end);
        $salesRefunds = $this->salesRefundsTotal($store, $range->start, $range->end);
        $netSales = round($grossSales - $salesRefunds, 2);

        [$previousGrossSales] = $this->grossSalesAndCount($store, $range->previousStart, $range->previousEnd);
        $previousSalesRefunds = $this->salesRefundsTotal($store, $range->previousStart, $range->previousEnd);
        $previousNetSales = round($previousGrossSales - $previousSalesRefunds, 2);

        return new SalesSummary(
            grossSales: $grossSales,
            salesRefunds: $salesRefunds,
            netSales: $netSales,
            orderCount: $orderCount,
            aov: $orderCount > 0 ? round($netSales / $orderCount, 2) : null,
            growthPercent: $this->growthPercent($netSales, $previousNetSales),
        );
    }

    /**
     * "Current status of orders created during the selected period" — a
     * v1 cohort view, deliberately not a per-status-transition-event view:
     * orders has no completed_at/shipped_at/refunded_at column, only
     * paid_at and cancelled_at, so there is no schema-supported way to
     * anchor "orders that became Completed/Refunded during the period."
     * All 7 OrderStatus values are always present in the result (zero-
     * filled), not just the ones PRD §11.4 names.
     *
     * @return array<string, int>
     */
    public function getOrderStatusBreakdown(Store $store, AnalyticsDateRange $range): array
    {
        $rows = Order::query()
            ->where('store_id', $store->id)
            ->where('created_at', '>=', $range->start)
            ->where('created_at', '<', $range->end)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->toBase()
            ->get();

        $counts = array_fill_keys(
            array_map(fn (OrderStatus $case) => $case->value, OrderStatus::cases()),
            0,
        );

        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->count;
        }

        return $counts;
    }

    /**
     * Gross basis only (PRD §11.2) — no per-line-item refund allocation is
     * attempted, since Phase 9D's refund model is full-order-only and has
     * no per-line refund data to allocate. Deleted historical items have
     * order_items.product_id = NULL (ON DELETE SET NULL); they are grouped
     * by the item's own snapshot product_name alongside product_id, so
     * distinctly-named deleted products stay distinguishable — two deleted
     * products that happened to share an identical historical name may
     * still merge into one row. Accepted v1 limitation, not a defect.
     *
     * daily_trend is computed only for the top-N products returned here
     * (never the full catalog), keeping the trend query bounded regardless
     * of catalog size.
     *
     * @return list<array{product_id: int|null, product_name: string, revenue: string, quantity_sold: int, daily_trend: list<array{date: string, revenue: string, quantity_sold: int}>}>
     */
    public function getProductAnalytics(Store $store, AnalyticsDateRange $range, string $sort, int $limit): array
    {
        $sortColumn = $sort === 'quantity' ? 'quantity_sold' : 'revenue';

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)
            ->whereIn('orders.status', SalesClassification::GROSS_SALE_STATUSES)
            ->where('orders.paid_at', '>=', $range->start)
            ->where('orders.paid_at', '<', $range->end)
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->selectRaw('order_items.product_id, order_items.product_name, SUM(order_items.line_total) as revenue, SUM(order_items.quantity) as quantity_sold')
            ->orderByDesc($sortColumn)
            ->limit($limit)
            ->toBase()
            ->get();

        $products = $rows->map(fn ($row) => [
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'product_name' => $row->product_name,
            'revenue' => number_format((float) $row->revenue, 2, '.', ''),
            'quantity_sold' => (int) $row->quantity_sold,
        ])->all();

        $productIds = array_values(array_unique(array_filter(
            array_column($products, 'product_id'),
            fn ($id) => $id !== null,
        )));
        $trends = $this->productDailyTrends($store, $range, $productIds);

        foreach ($products as &$product) {
            $product['daily_trend'] = $trends[$product['product_id']] ?? [];
        }
        unset($product);

        return $products;
    }

    /**
     * @return array{new_customers: int, returning_customers: int, top_customers: list<array{customer_id: int, name: string, email: string, net_sales: string, order_count: int}>}
     */
    public function getCustomerAnalytics(Store $store, AnalyticsDateRange $range, int $limit): array
    {
        $newCustomers = Customer::query()
            ->where('store_id', $store->id)
            ->where('created_at', '>=', $range->start)
            ->where('created_at', '<', $range->end)
            ->count();

        $returningCustomers = Customer::query()
            ->where('store_id', $store->id)
            ->whereExists(function ($query) use ($store, $range) {
                $query->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->where('orders.store_id', $store->id)
                    ->whereIn('orders.status', SalesClassification::GROSS_SALE_STATUSES)
                    ->where('orders.paid_at', '>=', $range->start)
                    ->where('orders.paid_at', '<', $range->end);
            })
            ->whereExists(function ($query) use ($store, $range) {
                $query->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->where('orders.store_id', $store->id)
                    ->whereIn('orders.status', SalesClassification::GROSS_SALE_STATUSES)
                    ->where('orders.paid_at', '<', $range->start);
            })
            ->count();

        return [
            'new_customers' => $newCustomers,
            'returning_customers' => $returningCustomers,
            'top_customers' => $this->topCustomers($store, $range, $limit),
        ];
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function grossSalesAndCount(Store $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = SalesClassification::scopeGrossSaleOrders(
            Order::query()->where('store_id', $store->id)
        )
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->selectRaw('COALESCE(SUM(total), 0) as gross_sales, COUNT(*) as order_count')
            ->toBase()
            ->first();

        return [round((float) $row->gross_sales, 2), (int) $row->order_count];
    }

    private function salesRefundsTotal(Store $store, CarbonImmutable $start, CarbonImmutable $end): float
    {
        $total = SalesRefundClassification::scopeSuccessfulSalesRefunds(
            Refund::query()
                ->join('orders', 'orders.id', '=', 'refunds.order_id')
                ->where('refunds.store_id', $store->id)
        )
            ->where('refunds.created_at', '>=', $start)
            ->where('refunds.created_at', '<', $end)
            ->selectRaw('COALESCE(SUM(refunds.amount), 0) as total')
            ->toBase()
            ->value('total');

        return round((float) $total, 2);
    }

    private function growthPercent(float $currentNetSales, float $previousNetSales): ?float
    {
        if ($previousNetSales === 0.0) {
            return $currentNetSales === 0.0 ? 0.0 : null;
        }

        return round((($currentNetSales - $previousNetSales) / $previousNetSales) * 100, 2);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, list<array{date: string, revenue: string, quantity_sold: int}>>
     */
    private function productDailyTrends(Store $store, AnalyticsDateRange $range, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)
            ->whereIn('orders.status', SalesClassification::GROSS_SALE_STATUSES)
            ->where('orders.paid_at', '>=', $range->start)
            ->where('orders.paid_at', '<', $range->end)
            ->whereIn('order_items.product_id', $productIds)
            ->selectRaw('order_items.product_id, DATE(orders.paid_at) as sale_date, SUM(order_items.line_total) as revenue, SUM(order_items.quantity) as quantity_sold')
            ->groupBy('order_items.product_id', 'sale_date')
            ->orderBy('sale_date')
            ->toBase()
            ->get();

        $trends = [];
        foreach ($rows as $row) {
            $trends[(int) $row->product_id][] = [
                'date' => (string) $row->sale_date,
                'revenue' => number_format((float) $row->revenue, 2, '.', ''),
                'quantity_sold' => (int) $row->quantity_sold,
            ];
        }

        return $trends;
    }

    /**
     * Per-customer Net Sales over the period, mirroring
     * CustomerController::withCustomerAggregates()'s withCount/withSum/
     * addSelect shape exactly, but date-bounded instead of all-time, and
     * ordered/limited at the database level (referencing the SELECT
     * aliases in ORDER BY, valid MySQL syntax) rather than fetching every
     * customer and sorting in PHP.
     *
     * @return list<array{customer_id: int, name: string, email: string, net_sales: string, order_count: int}>
     */
    private function topCustomers(Store $store, AnalyticsDateRange $range, int $limit): array
    {
        $customers = Customer::query()
            ->where('store_id', $store->id)
            ->withCount(['orders as order_count' => function (Builder $q) use ($range) {
                SalesClassification::scopeGrossSaleOrders($q)
                    ->where('paid_at', '>=', $range->start)
                    ->where('paid_at', '<', $range->end);
            }])
            ->withSum(['orders as gross_sales_amount' => function (Builder $q) use ($range) {
                SalesClassification::scopeGrossSaleOrders($q)
                    ->where('paid_at', '>=', $range->start)
                    ->where('paid_at', '<', $range->end);
            }], 'total')
            ->addSelect(['sales_refunds' => SalesRefundClassification::scopeSuccessfulSalesRefunds(
                Refund::query()
                    ->join('orders', 'orders.id', '=', 'refunds.order_id')
                    ->whereColumn('orders.customer_id', 'customers.id')
            )
                ->where('refunds.created_at', '>=', $range->start)
                ->where('refunds.created_at', '<', $range->end)
                ->selectRaw('COALESCE(SUM(refunds.amount), 0)'),
            ])
            ->orderByRaw('(COALESCE(gross_sales_amount, 0) - COALESCE(sales_refunds, 0)) DESC')
            ->limit($limit)
            ->get();

        return $customers->map(function (Customer $customer) {
            $netSales = round((float) ($customer->gross_sales_amount ?? 0) - (float) ($customer->sales_refunds ?? 0), 2);

            return [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'net_sales' => number_format($netSales, 2, '.', ''),
                'order_count' => (int) $customer->order_count,
            ];
        })->values()->all();
    }
}
