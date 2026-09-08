<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * Category authorization, following ProductPolicy's discipline exactly:
 * every method re-derives role/store-access against the actual
 * $store/$category argument via TenantAccess — never trusts that a bound
 * TenantContext refers to the same model being authorized here.
 *
 * Mutation is Owner-OR-Store-Admin, expressed with
 * OrganizationRole::atLeast(StoreAdmin) rather than an exact-match
 * comparison. Staff never mutates; no additional Staff restriction is
 * added beyond that — Staff simply doesn't pass the atLeast(StoreAdmin)
 * check.
 */
class CategoryPolicy
{
    /** Any org member with access to the store may list its categories. */
    public function viewAny(User $user, Store $store): bool
    {
        return $this->canReachStore($user, $store);
    }

    /** Owner (any store in their org) or a Store Admin/Staff with a store_user row for this category's store. */
    public function view(User $user, Category $category): bool
    {
        return $this->canReachStore($user, $category->store);
    }

    /** Create a category in this store — Store Admin or above, only while the organization is active. */
    public function create(User $user, Store $store): bool
    {
        return $this->canMutate($user, $store);
    }

    /** Update an existing category — Store Admin or above, only while the organization is active. */
    public function update(User $user, Category $category): bool
    {
        return $this->canMutate($user, $category->store);
    }

    /** Soft-delete a category — Store Admin or above, only while the organization is active. */
    public function delete(User $user, Category $category): bool
    {
        return $this->canMutate($user, $category->store);
    }

    private function canReachStore(User $user, Store $store): bool
    {
        $role = TenantAccess::roleFor($user, $store->organization);

        return $role !== null && TenantAccess::canAccessStore($user, $store, $role);
    }

    private function canMutate(User $user, Store $store): bool
    {
        $role = TenantAccess::roleFor($user, $store->organization);

        return $role !== null
            && $role->atLeast(OrganizationRole::StoreAdmin)
            && TenantAccess::canAccessStore($user, $store, $role)
            && $store->organization->status === OrganizationStatus::Active;
    }
}
