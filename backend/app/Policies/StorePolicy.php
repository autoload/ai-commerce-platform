<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * Store-level authorization. Every method re-derives the user's role (and,
 * for store-scoped methods, store access) against the actual $organization/
 * $store argument via TenantAccess — never trusts that a bound TenantContext
 * refers to the same model being authorized here, same discipline already
 * established by OrganizationPolicy.
 *
 * Store Admin and Staff are intentionally NOT differentiated in this
 * policy: within Block 3's scope (the Store record itself), both roles
 * have identical capability — view an assigned store, nothing more. The
 * distinction between them starts mattering once Products/Orders/etc. add
 * their own Policies (Store Admin manages them, Staff only views) — see
 * OrganizationRole::atLeast() for the hierarchy helper those will use.
 */
class StorePolicy
{
    /** Any org member may list stores — the actual per-role filtering happens in the query, not here. */
    public function viewAny(User $user, Organization $organization): bool
    {
        return TenantAccess::roleFor($user, $organization) !== null;
    }

    /** Owner (any store in their org) or a Store Admin/Staff with a store_user row for this store. */
    public function view(User $user, Store $store): bool
    {
        $role = TenantAccess::roleFor($user, $store->organization);

        return $role !== null && TenantAccess::canAccessStore($user, $store, $role);
    }

    /** Create a new store within the organization — Owner only, and only while the organization is active. */
    public function create(User $user, Organization $organization): bool
    {
        return TenantAccess::roleFor($user, $organization) === OrganizationRole::Owner
            && $organization->status === OrganizationStatus::Active;
    }

    /** Update an existing store — Owner only, and only while the organization is active. */
    public function update(User $user, Store $store): bool
    {
        return TenantAccess::roleFor($user, $store->organization) === OrganizationRole::Owner
            && $store->organization->status === OrganizationStatus::Active;
    }

    /** Soft-delete a store — Owner only, and only while the organization is active. */
    public function delete(User $user, Store $store): bool
    {
        return TenantAccess::roleFor($user, $store->organization) === OrganizationRole::Owner
            && $store->organization->status === OrganizationStatus::Active;
    }
}
