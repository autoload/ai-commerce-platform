<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * Order authorization, following ProductPolicy/InventoryPolicy's exact
 * discipline: every method re-derives role/store-access against the actual
 * $store/$order argument via TenantAccess — never trusts that a bound
 * TenantContext refers to the same model being authorized here.
 *
 * No create/delete — order creation belongs to the (not-yet-built)
 * CheckoutService, and there is no order-deletion use case at all.
 */
class OrderPolicy
{
    /** Any org member with access to the store may list its orders. */
    public function viewAny(User $user, Store $store): bool
    {
        return $this->canReachStore($user, $store);
    }

    /** Owner (any store in their org) or a Store Admin/Staff with a store_user row for this order's store. */
    public function view(User $user, Order $order): bool
    {
        return $this->canReachStore($user, $order->store);
    }

    /** Update an order's status — Store Admin or above, only while the organization is active. */
    public function updateStatus(User $user, Order $order): bool
    {
        $role = TenantAccess::roleFor($user, $order->store->organization);

        return $role !== null
            && $role->atLeast(OrganizationRole::StoreAdmin)
            && TenantAccess::canAccessStore($user, $order->store, $role)
            && $order->store->organization->status === OrganizationStatus::Active;
    }

    private function canReachStore(User $user, Store $store): bool
    {
        $role = TenantAccess::roleFor($user, $store->organization);

        return $role !== null && TenantAccess::canAccessStore($user, $store, $role);
    }
}
