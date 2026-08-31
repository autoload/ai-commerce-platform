<?php

namespace Tests\Unit\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Policies\OrganizationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class OrganizationPolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private OrganizationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new OrganizationPolicy;
    }

    public function test_owner_can_manage_the_organization(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);

        $this->assertTrue($this->policy->view($owner, $org));
        $this->assertTrue($this->policy->update($owner, $org));
        $this->assertTrue($this->policy->manageStores($owner, $org));
        $this->assertTrue($this->policy->manageUsers($owner, $org));
        $this->assertTrue($this->policy->viewAnalytics($owner, $org));
    }

    public function test_store_admin_can_view_but_not_manage_the_organization(): void
    {
        $org = Organization::factory()->create();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);

        $this->assertTrue($this->policy->view($storeAdmin, $org));
        $this->assertFalse($this->policy->update($storeAdmin, $org));
        $this->assertFalse($this->policy->manageStores($storeAdmin, $org));
        $this->assertFalse($this->policy->manageUsers($storeAdmin, $org));
        $this->assertFalse($this->policy->viewAnalytics($storeAdmin, $org));
    }

    public function test_staff_can_view_but_not_manage_the_organization(): void
    {
        $org = Organization::factory()->create();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);

        $this->assertTrue($this->policy->view($staff, $org));
        $this->assertFalse($this->policy->update($staff, $org));
        $this->assertFalse($this->policy->manageStores($staff, $org));
        $this->assertFalse($this->policy->manageUsers($staff, $org));
        $this->assertFalse($this->policy->viewAnalytics($staff, $org));
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);

        // Owner of orgB has no membership at all in orgA — every capability,
        // including plain "view", must be denied against orgA.
        $this->assertFalse($this->policy->view($ownerOfB, $orgA));
        $this->assertFalse($this->policy->update($ownerOfB, $orgA));
    }
}
