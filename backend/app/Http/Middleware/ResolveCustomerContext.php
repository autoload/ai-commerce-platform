<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationStatus;
use App\Enums\StoreStatus;
use App\Models\Store;
use App\Support\CustomerContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the CustomerContext for the currently authenticated customer and
 * binds it into the container for the rest of the request. Organization/
 * store are read directly from the authenticated customer's own
 * organization_id/store_id columns — request input (query params, headers,
 * body) is never consulted, so a client cannot influence which tenant it
 * resolves to. This mirrors ResolveMerchantTenantContext's principle, but
 * needs no membership-table lookup: a Customer's org/store are fixed at
 * creation, not a many-to-many membership.
 *
 * A soft-deleted store is resolved via a fresh query (not the cached
 * `$customer->store` relation) so Eloquent's SoftDeletes global scope
 * correctly excludes it, same as ResolveMerchantStoreContext's discipline
 * for merchant routes.
 */
class ResolveCustomerContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');

        $organization = $customer->organization;

        if (! $organization || $organization->status !== OrganizationStatus::Active) {
            abort(403, 'This store is not currently available.');
        }

        $store = Store::where('id', $customer->store_id)->first();

        if (! $store || $store->status !== StoreStatus::Active) {
            abort(403, 'This store is not currently available.');
        }

        app()->instance(CustomerContext::class, new CustomerContext(
            customer: $customer,
            organization: $organization,
            store: $store,
        ));

        return $next($request);
    }
}
