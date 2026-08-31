<?php

namespace Tests\Unit\Policies;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreUser;
use App\Policies\ProductPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class ProductPolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private ProductPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new ProductPolicy;
    }

    public function test_owner_can_manage_products_in_any_store_of_their_active_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($owner, $store));
        $this->assertTrue($this->policy->view($owner, $product));
        $this->assertTrue($this->policy->create($owner, $store));
        $this->assertTrue($this->policy->update($owner, $product));
        $this->assertTrue($this->policy->delete($owner, $product));
    }

    public function test_owner_has_implicit_access_without_a_store_user_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();

        $this->assertSame(0, StoreUser::where('user_id', $owner->id)->where('store_id', $store->id)->count());
        $this->assertTrue($this->policy->view($owner, $product));
    }

    public function test_store_admin_can_manage_products_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $product = Product::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($storeAdmin, $store));
        $this->assertTrue($this->policy->view($storeAdmin, $product));
        $this->assertTrue($this->policy->create($storeAdmin, $store));
        $this->assertTrue($this->policy->update($storeAdmin, $product));
        $this->assertTrue($this->policy->delete($storeAdmin, $product));
    }

    public function test_store_admin_is_denied_on_an_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assignedStore);
        $otherStore = Store::factory()->forOrganization($org)->create();
        $productInOtherStore = Product::factory()->forStore($otherStore)->create();

        $this->assertFalse($this->policy->viewAny($storeAdmin, $otherStore));
        $this->assertFalse($this->policy->view($storeAdmin, $productInOtherStore));
        $this->assertFalse($this->policy->create($storeAdmin, $otherStore));
        $this->assertFalse($this->policy->update($storeAdmin, $productInOtherStore));
        $this->assertFalse($this->policy->delete($storeAdmin, $productInOtherStore));
    }

    public function test_staff_can_read_but_not_mutate_products_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $product = Product::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($staff, $store));
        $this->assertTrue($this->policy->view($staff, $product));
        $this->assertFalse($this->policy->create($staff, $store));
        $this->assertFalse($this->policy->update($staff, $product));
        $this->assertFalse($this->policy->delete($staff, $product));
    }

    public function test_staff_has_no_additional_restriction_beyond_read_only(): void
    {
        // Staff's only limitation is the mutate gate itself — read access on
        // an assigned store must be identical to Store Admin's, not further
        // restricted.
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $this->attachToStore($staff, $store);
        $product = Product::factory()->forStore($store)->create();

        $this->assertSame(
            $this->policy->viewAny($storeAdmin, $store),
            $this->policy->viewAny($staff, $store),
        );
        $this->assertSame(
            $this->policy->view($storeAdmin, $product),
            $this->policy->view($staff, $product),
        );
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);
        $storeInA = Store::factory()->forOrganization($orgA)->create();
        $productInA = Product::factory()->forStore($storeInA)->create();

        $this->assertFalse($this->policy->view($ownerOfB, $productInA));
        $this->assertFalse($this->policy->update($ownerOfB, $productInA));
        $this->assertFalse($this->policy->delete($ownerOfB, $productInA));
    }

    public function test_owner_cannot_create_update_or_delete_products_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();

        $this->assertFalse($this->policy->create($owner, $store));
        $this->assertFalse($this->policy->update($owner, $product));
        $this->assertFalse($this->policy->delete($owner, $product));
    }

    public function test_owner_can_still_view_products_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();

        $this->assertTrue($this->policy->viewAny($owner, $store));
        $this->assertTrue($this->policy->view($owner, $product));
    }
}
