<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * No Analytics Eloquent model exists to key Laravel's naming-convention
 * policy auto-discovery off of — registered explicitly via Gate::define()
 * in AppServiceProvider::boot(), the same situation InventoryPolicy
 * already solved for ProductVariant. Unlike every other read Policy in
 * this codebase (OrderPolicy::view(), CustomerPolicy::view(), etc., all
 * open to Staff), Analytics requires Store Admin or above — PRD §20.3
 * does not grant Staff "View Analytics" at all, unlike Owner/Store Admin
 * (§20.1/§20.2).
 */
class AnalyticsPolicy
{
    /** Owner (any store in their org) or a Store Admin with a store_user row for this store. Staff is denied. */
    public function view(User $user, Store $store): bool
    {
        $role = TenantAccess::roleFor($user, $store->organization);

        return $role !== null
            && $role->atLeast(OrganizationRole::StoreAdmin)
            && TenantAccess::canAccessStore($user, $store, $role);
    }
}
