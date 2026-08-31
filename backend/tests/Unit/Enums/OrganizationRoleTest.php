<?php

namespace Tests\Unit\Enums;

use App\Enums\OrganizationRole;
use Tests\TestCase;

class OrganizationRoleTest extends TestCase
{
    public function test_owner_ranks_above_store_admin_and_staff(): void
    {
        $this->assertTrue(OrganizationRole::Owner->atLeast(OrganizationRole::Owner));
        $this->assertTrue(OrganizationRole::Owner->atLeast(OrganizationRole::StoreAdmin));
        $this->assertTrue(OrganizationRole::Owner->atLeast(OrganizationRole::Staff));
    }

    public function test_store_admin_ranks_above_staff_but_not_owner(): void
    {
        $this->assertFalse(OrganizationRole::StoreAdmin->atLeast(OrganizationRole::Owner));
        $this->assertTrue(OrganizationRole::StoreAdmin->atLeast(OrganizationRole::StoreAdmin));
        $this->assertTrue(OrganizationRole::StoreAdmin->atLeast(OrganizationRole::Staff));
    }

    public function test_staff_ranks_below_owner_and_store_admin(): void
    {
        $this->assertFalse(OrganizationRole::Staff->atLeast(OrganizationRole::Owner));
        $this->assertFalse(OrganizationRole::Staff->atLeast(OrganizationRole::StoreAdmin));
        $this->assertTrue(OrganizationRole::Staff->atLeast(OrganizationRole::Staff));
    }
}
