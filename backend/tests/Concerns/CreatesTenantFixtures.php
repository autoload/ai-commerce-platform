<?php

namespace Tests\Concerns;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;

/**
 * Shared fixture builders for tests exercising merchant tenant/RBAC
 * scenarios — extracted after the same three methods were duplicated
 * verbatim across every Block 3/4A test class (StorePolicyTest,
 * ProductPolicyTest, StoreManagementTest, ProductManagementTest,
 * StoreTenantIsolationTest, ProductTenantIsolationTest,
 * OrganizationPolicyTest). Behavior is unchanged — this is a pure
 * extraction, same as the App\Support\TenantAccess consolidation in
 * application code after Block 2.
 */
trait CreatesTenantFixtures
{
    private function activeOrganization(): Organization
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();

        return $org;
    }

    private function memberWithRole(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create();

        $membership = new OrganizationUser;
        $membership->organization_id = $org->id;
        $membership->user_id = $user->id;
        $membership->role = $role;
        $membership->save();

        return $user;
    }

    private function attachToStore(User $user, Store $store): void
    {
        $storeUser = new StoreUser;
        $storeUser->user_id = $user->id;
        $storeUser->store_id = $store->id;
        $storeUser->save();
    }
}
