<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Refund;
use App\Support\SalesClassification;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * {store} is already resolved and access-verified by tenant.merchant.store
 * — no new middleware introduced, per the approved Phase 9C design.
 * {customer} is deliberately NOT implicitly route-bound: resolveCustomer()
 * queries it scoped to $context->store->id itself, the same discipline
 * ProductController/OrderController/CategoryController use for
 * {product}/{order}/{category} — a client-supplied customer id belonging
 * to a different store can never be reached.
 *
 * Read-only (Phase 9C approved scope) — index/show only, no store/update/
 * destroy. order_count/total_spent are always computed database-side
 * (withCount/withSum/addSelect) on both the list and detail queries, never
 * in a PHP loop — see withCustomerAggregates().
 *
 * total_spent (cross-module consistency fix, following Phase 9E-4):
 * originally summed orders.total for every status except pending/cancelled,
 * which incorrectly kept a fully-refunded order's amount in a customer's
 * spend. It is now Net Sales per customer (Gross Sales − Sales Refunds),
 * using the exact same App\Support\SalesClassification::GROSS_SALE_STATUSES
 * definition the future AnalyticsService will use — so Customer Management
 * and Analytics can never disagree about a customer's revenue. G3-B/9E-4
 * compensation refunds never reduce total_spent: their order is always
 * Cancelled, which is never in GROSS_SALE_STATUSES, so the sales_refunds
 * subquery below structurally excludes them without ever inspecting
 * status_reason.
 */
class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $context = app(TenantContext::class);

        Gate::authorize('viewAny', [Customer::class, $context->store]);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = $this->withCustomerAggregates(
            Customer::where('store_id', $context->store->id)
        );

        if (! empty($validated['search'])) {
            $term = $validated['search'];
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $customers = $query->orderBy('created_at', 'desc')->paginate(15);

        return CustomerResource::collection($customers);
    }

    public function show(Request $request): CustomerResource
    {
        $context = app(TenantContext::class);
        $customer = $this->resolveCustomer($request, $context);

        Gate::authorize('view', $customer);

        return new CustomerResource($customer);
    }

    /**
     * order_count counts ALL of the customer's orders regardless of status
     * (approved Phase 9C semantics, unchanged). gross_sales_amount and
     * sales_refunds are single database-side aggregate subqueries appended
     * to the customers query — never one query per customer — combined
     * into total_spent by CustomerResource. See SalesClassification for the
     * authoritative "which orders count as a sale" definition both figures
     * share, and this class's own docblock for why G3-B/9E-4 compensation
     * refunds are excluded without inspecting status_reason.
     *
     * sales_refunds is a manually-built correlated subquery (join + where,
     * not a nested withSum('orders.refunds', ...)) — Eloquent's
     * withAggregate() does not safely support a two-hop relation aggregate
     * like Customer -> orders -> refunds: it would inject a second,
     * unconstrained aggregate attempt against the intermediate `orders`
     * relation using the same column ('amount'), which orders has no
     * column named, producing broken SQL. This subquery avoids that
     * entirely while remaining a single query, no N+1.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function withCustomerAggregates(Builder $query): Builder
    {
        return $query
            ->withCount('orders')
            ->withSum(['orders as gross_sales_amount' => function (Builder $q) {
                SalesClassification::scopeGrossSaleOrders($q);
            }], 'total')
            ->addSelect(['sales_refunds' => Refund::query()
                ->selectRaw('SUM(refunds.amount)')
                ->join('orders', 'orders.id', '=', 'refunds.order_id')
                ->whereColumn('orders.customer_id', 'customers.id')
                ->where('refunds.status', RefundStatus::Succeeded)
                ->whereIn('orders.status', SalesClassification::GROSS_SALE_STATUSES),
            ]);
    }

    private function resolveCustomer(Request $request, TenantContext $context): Customer
    {
        $customer = $this->withCustomerAggregates(
            Customer::where('id', $request->route('customer'))
                ->where('store_id', $context->store->id)
        )->first();

        if (! $customer) {
            abort(404);
        }

        return $customer;
    }
}
