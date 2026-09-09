<?php

namespace Tests\Unit\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use App\Policies\CustomerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class CustomerPolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private CustomerPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CustomerPolicy;
    }

    public function test_owner_can_view_customers_in_any_store_of_their_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($owner, $store));
        $this->assertTrue($this->policy->view($owner, $customer));
    }

    public function test_owner_has_implicit_access_without_a_store_user_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->view($owner, $customer));
    }

    public function test_store_admin_can_view_customers_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $customer = Customer::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($storeAdmin, $store));
        $this->assertTrue($this->policy->view($storeAdmin, $customer));
    }

    public function test_store_admin_is_denied_on_an_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assignedStore);
        $otherStore = Store::factory()->forOrganization($org)->create();
        $customerInOtherStore = Customer::factory()->forStore($otherStore)->create();

        $this->assertFalse($this->policy->viewAny($storeAdmin, $otherStore));
        $this->assertFalse($this->policy->view($storeAdmin, $customerInOtherStore));
    }

    public function test_staff_can_view_customers_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $customer = Customer::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($staff, $store));
        $this->assertTrue($this->policy->view($staff, $customer));
    }

    public function test_staff_is_denied_on_an_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $assignedStore);
        $otherStore = Store::factory()->forOrganization($org)->create();
        $customerInOtherStore = Customer::factory()->forStore($otherStore)->create();

        $this->assertFalse($this->policy->viewAny($staff, $otherStore));
        $this->assertFalse($this->policy->view($staff, $customerInOtherStore));
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);
        $storeInA = Store::factory()->forOrganization($orgA)->create();
        $customerInA = Customer::factory()->forStore($storeInA)->create();

        $this->assertFalse($this->policy->viewAny($ownerOfB, $storeInA));
        $this->assertFalse($this->policy->view($ownerOfB, $customerInA));
    }

    public function test_owner_can_still_view_customers_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($owner, $store));
        $this->assertTrue($this->policy->view($owner, $customer));
    }
}
