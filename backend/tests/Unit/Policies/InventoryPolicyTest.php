<?php

namespace Tests\Unit\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Policies\InventoryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class InventoryPolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private InventoryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new InventoryPolicy;
    }

    public function test_owner_can_view_and_adjust_inventory_in_any_store_of_their_active_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertTrue($this->policy->view($owner, $variant));
        $this->assertTrue($this->policy->adjust($owner, $variant));
    }

    public function test_owner_has_implicit_access_without_a_store_user_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertTrue($this->policy->adjust($owner, $variant));
    }

    public function test_store_admin_can_view_and_adjust_inventory_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertTrue($this->policy->view($storeAdmin, $variant));
        $this->assertTrue($this->policy->adjust($storeAdmin, $variant));
    }

    public function test_store_admin_cannot_adjust_inventory_on_an_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assignedStore);
        $otherStore = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($otherStore)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertFalse($this->policy->view($storeAdmin, $variant));
        $this->assertFalse($this->policy->adjust($storeAdmin, $variant));
    }

    public function test_staff_can_view_but_not_adjust_inventory_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertTrue($this->policy->view($staff, $variant));
        $this->assertFalse($this->policy->adjust($staff, $variant));
    }

    public function test_staff_has_no_additional_restriction_beyond_read_only(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $this->attachToStore($staff, $store);
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertSame(
            $this->policy->view($storeAdmin, $variant),
            $this->policy->view($staff, $variant),
        );
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);
        $storeInA = Store::factory()->forOrganization($orgA)->create();
        $productInA = Product::factory()->forStore($storeInA)->create();
        $variantInA = ProductVariant::factory()->forProduct($productInA)->create();

        $this->assertFalse($this->policy->view($ownerOfB, $variantInA));
        $this->assertFalse($this->policy->adjust($ownerOfB, $variantInA));
    }

    public function test_owner_cannot_adjust_inventory_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertFalse($this->policy->adjust($owner, $variant));
    }

    public function test_owner_can_still_view_inventory_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->assertTrue($this->policy->view($owner, $variant));
    }
}
