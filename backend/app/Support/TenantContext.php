<?php

namespace App\Support;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

/**
 * Server-resolved merchant tenant identity for the current request — never
 * built from client-supplied organization_id/store_id. Bound into the
 * container by ResolveMerchantTenantContext middleware; controllers,
 * policies, and (later) Eloquent scopes read from this instance instead of
 * re-deriving tenant identity themselves.
 */
final class TenantContext
{
    public function __construct(
        public readonly User $user,
        public readonly Organization $organization,
        public readonly OrganizationRole $role,
        public readonly ?Store $store = null,
    ) {}
}
