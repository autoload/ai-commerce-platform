<?php

namespace Tests\Unit\Support;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_for_returns_the_users_role_in_the_given_organization(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attachOrg($org, $user, OrganizationRole::StoreAdmin);

        $this->assertSame(OrganizationRole::StoreAdmin, TenantAccess::roleFor($user, $org));
    }

    public function test_role_for_returns_null_when_no_membership_exists(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $this->assertNull(TenantAccess::roleFor($user, $org));
    }

    public function test_role_for_does_not_leak_across_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attachOrg($orgA, $user, OrganizationRole::Owner);

        $this->assertSame(OrganizationRole::Owner, TenantAccess::roleFor($user, $orgA));
        $this->assertNull(TenantAccess::roleFor($user, $orgB));
    }

    public function test_membership_for_returns_the_pivot_row_with_organization_loaded(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attachOrg($org, $user, OrganizationRole::Owner);

        $membership = TenantAccess::membershipFor($user);

        $this->assertNotNull($membership);
        $this->assertSame(OrganizationRole::Owner, $membership->role);
        $this->assertTrue($membership->organization->is($org));
    }

    public function test_membership_for_returns_null_when_user_has_no_organization(): void
    {
        $user = User::factory()->create();

        $this->assertNull(TenantAccess::membershipFor($user));
    }

    public function test_owner_can_access_any_store_without_a_store_user_row(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->attachOrg($org, $owner, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();

        $this->assertTrue(TenantAccess::canAccessStore($owner, $store, OrganizationRole::Owner));
    }

    public function test_store_admin_cannot_access_a_store_without_a_store_user_row(): void
    {
        $org = Organization::factory()->create();
        $storeAdmin = User::factory()->create();
        $this->attachOrg($org, $storeAdmin, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();

        $this->assertFalse(TenantAccess::canAccessStore($storeAdmin, $store, OrganizationRole::StoreAdmin));
    }

    public function test_store_admin_can_access_a_store_with_a_store_user_row(): void
    {
        $org = Organization::factory()->create();
        $storeAdmin = User::factory()->create();
        $this->attachOrg($org, $storeAdmin, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();

        $storeUser = new StoreUser;
        $storeUser->user_id = $storeAdmin->id;
        $storeUser->store_id = $store->id;
        $storeUser->save();

        $this->assertTrue(TenantAccess::canAccessStore($storeAdmin, $store, OrganizationRole::StoreAdmin));
    }

    private function attachOrg(Organization $org, User $user, OrganizationRole $role): void
    {
        $membership = new OrganizationUser;
        $membership->organization_id = $org->id;
        $membership->user_id = $user->id;
        $membership->role = $role;
        $membership->save();
    }
}
