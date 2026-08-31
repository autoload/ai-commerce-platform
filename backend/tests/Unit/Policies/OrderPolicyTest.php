<?php

namespace Tests\Unit\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Policies\OrderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class OrderPolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private OrderPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new OrderPolicy;
    }

    public function test_owner_can_view_and_update_orders_in_any_store_of_their_active_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->view($owner, $order));
        $this->assertTrue($this->policy->updateStatus($owner, $order));
    }

    public function test_owner_has_implicit_access_without_a_store_user_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->updateStatus($owner, $order));
    }

    public function test_store_admin_can_view_and_update_orders_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $order = Order::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->view($storeAdmin, $order));
        $this->assertTrue($this->policy->updateStatus($storeAdmin, $order));
    }

    public function test_store_admin_cannot_view_or_update_orders_on_an_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assignedStore);
        $otherStore = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($otherStore)->create();

        $this->assertFalse($this->policy->view($storeAdmin, $order));
        $this->assertFalse($this->policy->updateStatus($storeAdmin, $order));
    }

    public function test_staff_can_view_but_not_update_status_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $order = Order::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->view($staff, $order));
        $this->assertFalse($this->policy->updateStatus($staff, $order));
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);
        $storeInA = Store::factory()->forOrganization($orgA)->create();
        $orderInA = Order::factory()->forStore($storeInA)->create();

        $this->assertFalse($this->policy->view($ownerOfB, $orderInA));
        $this->assertFalse($this->policy->updateStatus($ownerOfB, $orderInA));
    }

    public function test_owner_cannot_update_status_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();

        $this->assertFalse($this->policy->updateStatus($owner, $order));
    }

    public function test_owner_can_still_view_orders_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->view($owner, $order));
    }
}
