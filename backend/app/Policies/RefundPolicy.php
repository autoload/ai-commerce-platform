<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * Refund authorization, following OrderPolicy's discipline exactly: every
 * method re-derives role/store-access against the actual $store/$order/
 * $refund argument via TenantAccess — never trusts that a bound
 * TenantContext refers to the same model being authorized here.
 *
 * No update/delete — refunds are append-only from the merchant's
 * perspective; only the webhook ever transitions status, and it isn't a
 * Policy-gated actor. `create` is authorized against Order (the resource
 * named in the route), matching OrderPolicy::updateStatus(User, Order)'s
 * exact signature shape.
 */
class RefundPolicy
{
    /** Owner (any store in their org) or a Store Admin/Staff with a store_user row for this refund's store. */
    public function view(User $user, Refund $refund): bool
    {
        return $this->canReachStore($user, $refund->store);
    }

    /** Initiate a refund for this order — Store Admin or above, only while the organization is active. */
    public function create(User $user, Order $order): bool
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
