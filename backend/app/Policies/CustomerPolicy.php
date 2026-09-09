<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * Customer authorization, following OrderPolicy's read-only discipline
 * exactly: every method re-derives role/store-access against the actual
 * $store/$customer argument via TenantAccess — never trusts that a bound
 * TenantContext refers to the same model being authorized here.
 *
 * Read-only (Phase 9C) — no create/update/delete. Unlike ProductPolicy/
 * CategoryPolicy's mutate gate, there is no organization-active check
 * here either, matching OrderPolicy::view()/viewAny()'s precedent (only
 * OrderPolicy::updateStatus() — a mutation — checks organization.status;
 * viewing is never gated on it).
 */
class CustomerPolicy
{
    /** Any org member with access to the store may list its customers. */
    public function viewAny(User $user, Store $store): bool
    {
        return $this->canReachStore($user, $store);
    }

    /** Owner (any store in their org) or a Store Admin/Staff with a store_user row for this customer's store. */
    public function view(User $user, Customer $customer): bool
    {
        return $this->canReachStore($user, $customer->store);
    }

    private function canReachStore(User $user, Store $store): bool
    {
        $role = TenantAccess::roleFor($user, $store->organization);

        return $role !== null && TenantAccess::canAccessStore($user, $store, $role);
    }
}
