<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\TenantAccess;

/**
 * First Policy in the merchant RBAC layer — establishes the pattern later
 * feature blocks (Store/Product/Order/... Policies) follow: resolve the
 * acting user's role for the target tenant-scoped model directly from
 * organization_user, then gate each capability by the documented role
 * hierarchy (Owner ⊃ Store Admin ⊃ Staff). Never trusts a client-supplied
 * role or organization id — the membership row is always looked up fresh.
 */
class OrganizationPolicy
{
    /** Any member of the organization may view it. */
    public function view(User $user, Organization $organization): bool
    {
        return $this->roleFor($user, $organization) !== null;
    }

    /** Manage the organization itself — Owner only. */
    public function update(User $user, Organization $organization): bool
    {
        return $this->roleFor($user, $organization) === OrganizationRole::Owner;
    }

    /** Create/manage stores within the organization — Owner only. */
    public function manageStores(User $user, Organization $organization): bool
    {
        return $this->roleFor($user, $organization) === OrganizationRole::Owner;
    }

    /** Manage organization users and their roles — Owner only. */
    public function manageUsers(User $user, Organization $organization): bool
    {
        return $this->roleFor($user, $organization) === OrganizationRole::Owner;
    }

    /** Organization-wide (cross-store) analytics/AI — Owner only; Store Admin's analytics access is store-scoped, not modeled here. */
    public function viewAnalytics(User $user, Organization $organization): bool
    {
        return $this->roleFor($user, $organization) === OrganizationRole::Owner;
    }

    private function roleFor(User $user, Organization $organization): ?OrganizationRole
    {
        return TenantAccess::roleFor($user, $organization);
    }
}
