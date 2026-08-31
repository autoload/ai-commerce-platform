<?php

namespace App\Support;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;

/**
 * Single source of truth for "what is this user's role/access within this
 * tenant" lookups. Consolidates logic that was previously duplicated across
 * ResolveMerchantTenantContext, MerchantAuthController::login(), and
 * OrganizationPolicy — every one of those now delegates here instead of
 * re-querying organization_user/store_user independently.
 */
final class TenantAccess
{
    /**
     * The authenticated user's organization_user membership row, with its
     * Organization eager-loaded — the "discover my org from scratch" shape
     * needed when only the user is known yet (session/context resolution,
     * login). Not for checking access to a specific, already-known
     * Organization — use roleFor() for that.
     */
    public static function membershipFor(User $user): ?OrganizationUser
    {
        return OrganizationUser::query()
            ->with('organization')
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * The user's role within the given organization, or null if they have
     * no organization_user membership row for it at all. Always re-verified
     * against the specific $organization passed in — never assume it's the
     * same organization a bound TenantContext already resolved.
     */
    public static function roleFor(User $user, Organization $organization): ?OrganizationRole
    {
        return OrganizationUser::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->first()
            ?->role;
    }

    /**
     * Whether the user may access the given store. Owner has implicit
     * access to every store in their own organization (no store_user row
     * required); Store Admin/Staff require an explicit store_user row.
     * Callers are responsible for having already verified $store belongs to
     * the user's organization — this method only expresses the role-based
     * access rule, not tenant/org membership itself.
     */
    public static function canAccessStore(User $user, Store $store, OrganizationRole $role): bool
    {
        if ($role === OrganizationRole::Owner) {
            return true;
        }

        return StoreUser::query()
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->exists();
    }
}
