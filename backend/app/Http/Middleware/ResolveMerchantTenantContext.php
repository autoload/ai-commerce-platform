<?php

namespace App\Http\Middleware;

use App\Models\OrganizationUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the merchant TenantContext for the currently authenticated
 * merchant user and binds it into the container for the rest of the
 * request. Organization/role are derived exclusively from the
 * authenticated user's own organization_user row — request input
 * (query params, headers, body) is never consulted, so a client cannot
 * influence which tenant it resolves to.
 */
class ResolveMerchantTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('merchant');

        $membership = OrganizationUser::with('organization')
            ->where('user_id', $user->id)
            ->first();

        if (! $membership || ! $membership->organization) {
            abort(403, 'No organization membership found for this account.');
        }

        app()->instance(TenantContext::class, new TenantContext(
            user: $user,
            organization: $membership->organization,
            role: $membership->role,
        ));

        return $next($request);
    }
}
