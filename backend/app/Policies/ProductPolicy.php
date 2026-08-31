<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * Product authorization, following StorePolicy's discipline exactly: every
 * method re-derives role/store-access against the actual $store/$product
 * argument via TenantAccess — never trusts that a bound TenantContext
 * refers to the same model being authorized here.
 *
 * Unlike StorePolicy (Owner-only, since "manage stores" is an Owner-only
 * capability), Product mutation is Owner-OR-Store-Admin — expressed with
 * OrganizationRole::atLeast(StoreAdmin) rather than an exact-match
 * comparison, since two roles now qualify. Staff never mutates; no
 * additional Staff restriction is added beyond that — Staff simply doesn't
 * pass the atLeast(StoreAdmin) check.
 */
class ProductPolicy
{
    /** Any org member with access to the store may list its products. */
    public function viewAny(User $user, Store $store): bool
    {
        return $this->canReachStore($user, $store);
    }

    /** Owner (any store in their org) or a Store Admin/Staff with a store_user row for this product's store. */
    public function view(User $user, Product $product): bool
    {
        return $this->canReachStore($user, $product->store);
    }

    /** Create a product in this store — Store Admin or above, only while the organization is active. */
    public function create(User $user, Store $store): bool
    {
        return $this->canMutate($user, $store);
    }

    /** Update an existing product — Store Admin or above, only while the organization is active. */
    public function update(User $user, Product $product): bool
    {
        return $this->canMutate($user, $product->store);
    }

    /** Soft-delete a product — Store Admin or above, only while the organization is active. */
    public function delete(User $user, Product $product): bool
    {
        return $this->canMutate($user, $product->store);
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
