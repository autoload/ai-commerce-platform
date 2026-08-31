<?php

namespace Tests\Unit\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreUser;
use App\Policies\StorePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class StorePolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private StorePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new StorePolicy;
    }

    public function test_owner_can_manage_any_store_in_their_active_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();

        $this->assertTrue($this->policy->viewAny($owner, $org));
        $this->assertTrue($this->policy->view($owner, $store));
        $this->assertTrue($this->policy->create($owner, $org));
        $this->assertTrue($this->policy->update($owner, $store));
        $this->assertTrue($this->policy->delete($owner, $store));
    }

    public function test_owner_has_implicit_access_without_a_store_user_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();

        $this->assertSame(0, StoreUser::where('user_id', $owner->id)->where('store_id', $store->id)->count());
        $this->assertTrue($this->policy->view($owner, $store));
    }

    public function test_store_admin_can_view_only_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $otherStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assignedStore);

        $this->assertTrue($this->policy->view($storeAdmin, $assignedStore));
        $this->assertFalse($this->policy->view($storeAdmin, $otherStore));
        $this->assertFalse($this->policy->create($storeAdmin, $org));
        $this->assertFalse($this->policy->update($storeAdmin, $assignedStore));
        $this->assertFalse($this->policy->delete($storeAdmin, $assignedStore));
    }

    public function test_staff_can_view_only_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $otherStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $assignedStore);

        $this->assertTrue($this->policy->view($staff, $assignedStore));
        $this->assertFalse($this->policy->view($staff, $otherStore));
        $this->assertFalse($this->policy->create($staff, $org));
        $this->assertFalse($this->policy->update($staff, $assignedStore));
        $this->assertFalse($this->policy->delete($staff, $assignedStore));
    }

    public function test_store_admin_and_staff_have_identical_store_level_capability(): void
    {
        // Deliberately not differentiated in Block 3 — see the class docblock
        // on StorePolicy.
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $this->attachToStore($staff, $store);

        $this->assertSame(
            $this->policy->view($storeAdmin, $store),
            $this->policy->view($staff, $store),
        );
        $this->assertSame(
            $this->policy->update($storeAdmin, $store),
            $this->policy->update($staff, $store),
        );
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);
        $storeInA = Store::factory()->forOrganization($orgA)->create();

        $this->assertFalse($this->policy->view($ownerOfB, $storeInA));
        $this->assertFalse($this->policy->update($ownerOfB, $storeInA));
        $this->assertFalse($this->policy->delete($ownerOfB, $storeInA));
    }

    public function test_owner_cannot_create_update_or_delete_stores_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();

        $this->assertFalse($this->policy->create($owner, $org));
        $this->assertFalse($this->policy->update($owner, $store));
        $this->assertFalse($this->policy->delete($owner, $store));
    }

    public function test_owner_can_still_view_stores_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();

        $this->assertTrue($this->policy->viewAny($owner, $org));
        $this->assertTrue($this->policy->view($owner, $store));
    }
}
