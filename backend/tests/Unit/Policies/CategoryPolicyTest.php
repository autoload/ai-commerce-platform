<?php

namespace Tests\Unit\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreUser;
use App\Policies\CategoryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class CategoryPolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private CategoryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CategoryPolicy;
    }

    public function test_owner_can_manage_categories_in_any_store_of_their_active_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($owner, $store));
        $this->assertTrue($this->policy->view($owner, $category));
        $this->assertTrue($this->policy->create($owner, $store));
        $this->assertTrue($this->policy->update($owner, $category));
        $this->assertTrue($this->policy->delete($owner, $category));
    }

    public function test_owner_has_implicit_access_without_a_store_user_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();

        $this->assertSame(0, StoreUser::where('user_id', $owner->id)->where('store_id', $store->id)->count());
        $this->assertTrue($this->policy->view($owner, $category));
    }

    public function test_store_admin_can_manage_categories_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $category = Category::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($storeAdmin, $store));
        $this->assertTrue($this->policy->view($storeAdmin, $category));
        $this->assertTrue($this->policy->create($storeAdmin, $store));
        $this->assertTrue($this->policy->update($storeAdmin, $category));
        $this->assertTrue($this->policy->delete($storeAdmin, $category));
    }

    public function test_store_admin_is_denied_on_an_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assignedStore);
        $otherStore = Store::factory()->forOrganization($org)->create();
        $categoryInOtherStore = Category::factory()->forStore($otherStore)->create();

        $this->assertFalse($this->policy->viewAny($storeAdmin, $otherStore));
        $this->assertFalse($this->policy->view($storeAdmin, $categoryInOtherStore));
        $this->assertFalse($this->policy->create($storeAdmin, $otherStore));
        $this->assertFalse($this->policy->update($storeAdmin, $categoryInOtherStore));
        $this->assertFalse($this->policy->delete($storeAdmin, $categoryInOtherStore));
    }

    public function test_staff_can_read_but_not_mutate_categories_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $category = Category::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($staff, $store));
        $this->assertTrue($this->policy->view($staff, $category));
        $this->assertFalse($this->policy->create($staff, $store));
        $this->assertFalse($this->policy->update($staff, $category));
        $this->assertFalse($this->policy->delete($staff, $category));
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);
        $storeInA = Store::factory()->forOrganization($orgA)->create();
        $categoryInA = Category::factory()->forStore($storeInA)->create();

        $this->assertFalse($this->policy->view($ownerOfB, $categoryInA));
        $this->assertFalse($this->policy->update($ownerOfB, $categoryInA));
        $this->assertFalse($this->policy->delete($ownerOfB, $categoryInA));
    }

    public function test_owner_cannot_create_update_or_delete_categories_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();

        $this->assertFalse($this->policy->create($owner, $store));
        $this->assertFalse($this->policy->update($owner, $category));
        $this->assertFalse($this->policy->delete($owner, $category));
    }

    public function test_owner_can_still_view_categories_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($owner, $store));
        $this->assertTrue($this->policy->view($owner, $category));
    }
}
