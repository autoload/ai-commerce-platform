<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\TenantAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enriches the already-bound (org-level) TenantContext with a verified
 * Store for store-scoped merchant routes. Must run after tenant.merchant.
 *
 * Deliberately does NOT rely on Laravel's implicit route-model binding for
 * {store} — that would resolve Store::findOrFail($id) with zero tenant
 * awareness, and depending on binding-vs-middleware execution order for a
 * security-load-bearing check is not worth the risk. Instead this
 * middleware resolves the store itself, scoped to the resolved
 * organization, so the {store} URL segment (client-supplied) can never
 * reach a store outside the caller's own organization.
 */
class ResolveMerchantStoreContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        $store = Store::query()
            ->where('id', $request->route('store'))
            ->where('organization_id', $context->organization->id)
            ->first();

        // Wrong organization or nonexistent: 404, not 403 — a store
        // belonging to a different organization must not be distinguishable
        // from a store that doesn't exist at all.
        if (! $store) {
            abort(404);
        }

        // Belongs to the right organization but this role can't reach it
        // (Store Admin/Staff with no store_user row): 403 is fine here,
        // since the caller already knows their own organization has a
        // store at this id.
        if (! TenantAccess::canAccessStore($context->user, $store, $context->role)) {
            abort(403);
        }

        app()->instance(TenantContext::class, new TenantContext(
            user: $context->user,
            organization: $context->organization,
            role: $context->role,
            store: $store,
        ));

        return $next($request);
    }
}
