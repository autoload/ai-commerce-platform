<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Support\AnalyticsDateRange;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * {store} is already resolved and access-verified by tenant.merchant.store
 * — no new middleware introduced, matching every other merchant
 * controller. Four focused endpoints (sales/orders/products/customers),
 * each backed by its own AnalyticsService method — deliberately not one
 * combined "dashboard" endpoint, per the approved Analytics v1 Design.
 * Access is gated by the 'viewAnalytics' ability (AnalyticsPolicy,
 * registered in AppServiceProvider — Analytics has no Eloquent model to
 * auto-discover a policy from). Owner/Store Admin only — Staff receives
 * 403.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
    ) {}

    public function sales(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        Gate::authorize('viewAnalytics', $context->store);

        $range = $this->resolveRange($request);
        $summary = $this->analyticsService->getSalesSummary($context->store, $range);

        return response()->json(['data' => [
            'range' => $range->toArray(),
            'previous_range' => $range->previousToArray(),
            'gross_sales' => number_format($summary->grossSales, 2, '.', ''),
            'sales_refunds' => number_format($summary->salesRefunds, 2, '.', ''),
            'net_sales' => number_format($summary->netSales, 2, '.', ''),
            'order_count' => $summary->orderCount,
            'aov' => $summary->aov !== null ? number_format($summary->aov, 2, '.', '') : null,
            'growth_percent' => $summary->growthPercent,
        ]]);
    }

    public function orders(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        Gate::authorize('viewAnalytics', $context->store);

        $range = $this->resolveRange($request);
        $counts = $this->analyticsService->getOrderStatusBreakdown($context->store, $range);

        return response()->json(['data' => [
            'range' => $range->toArray(),
            'counts' => $counts,
        ]]);
    }

    public function products(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        Gate::authorize('viewAnalytics', $context->store);

        $range = $this->resolveRange($request);
        $validated = $request->validate([
            'sort' => ['nullable', Rule::in(['revenue', 'quantity'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $products = $this->analyticsService->getProductAnalytics(
            $context->store,
            $range,
            $validated['sort'] ?? 'revenue',
            $validated['limit'] ?? 10,
        );

        return response()->json(['data' => [
            'range' => $range->toArray(),
            'products' => $products,
        ]]);
    }

    public function customers(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        Gate::authorize('viewAnalytics', $context->store);

        $range = $this->resolveRange($request);
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->analyticsService->getCustomerAnalytics($context->store, $range, $validated['limit'] ?? 10);

        return response()->json(['data' => array_merge(['range' => $range->toArray()], $result)]);
    }

    private function resolveRange(Request $request): AnalyticsDateRange
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(AnalyticsDateRange::PRESETS)],
        ]);

        return AnalyticsDateRange::resolve($validated['range'] ?? 'last_30_days');
    }
}
