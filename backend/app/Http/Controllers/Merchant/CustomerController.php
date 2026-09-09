<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
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
 * (withCount/withSum) on both the list and detail queries, never in a PHP
 * loop — see withCustomerAggregates().
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
     * order_count counts ALL of the customer's orders regardless of
     * status (approved Phase 9C semantics). total_spent sums orders.total
     * for every status except pending/cancelled — refunded is currently
     * included (no merchant refund workflow exists yet to make this
     * decision moot in any other way; see the Phase 9C design report's
     * "Refund Boundary" section). Both are single database-side aggregate
     * subqueries appended to the customers query — never one query per
     * customer.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function withCustomerAggregates(Builder $query): Builder
    {
        return $query
            ->withCount('orders')
            ->withSum([
                'orders as total_spent' => function (Builder $q) {
                    $q->whereNotIn('status', ['pending', 'cancelled']);
                },
            ], 'total');
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
