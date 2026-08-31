<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * Inventory authorization, keyed off the ProductVariant it belongs to
 * (inventory/inventory_transactions carry no organization_id/store_id of
 * their own — only product_variant_id). Same discipline as
 * StorePolicy/ProductPolicy: every method re-derives role/store-access
 * against the actual $variant argument via TenantAccess.
 */
class InventoryPolicy
{
    /** Any role with access to the variant's store may view its inventory. */
    public function view(User $user, ProductVariant $variant): bool
    {
        $role = TenantAccess::roleFor($user, $variant->store->organization);

        return $role !== null && TenantAccess::canAccessStore($user, $variant->store, $role);
    }

    /** Adjust inventory — Store Admin or above, only while the organization is active. */
    public function adjust(User $user, ProductVariant $variant): bool
    {
        $role = TenantAccess::roleFor($user, $variant->store->organization);

        return $role !== null
            && $role->atLeast(OrganizationRole::StoreAdmin)
            && TenantAccess::canAccessStore($user, $variant->store, $role)
            && $variant->store->organization->status === OrganizationStatus::Active;
    }
}
