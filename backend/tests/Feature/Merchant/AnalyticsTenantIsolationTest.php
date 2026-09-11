<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\PlatformAdmin;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Analytics v1 — store tenant isolation and RBAC. Mirrors
 * CustomerTenantIsolationTest's exact shape (same 404-vs-403 discipline,
 * same cross-guard checks), plus the RBAC matrix required by the approved
 * design: Owner/Store Admin allowed, Staff denied on every one of the four
 * endpoints — the first merchant resource in this codebase where Staff is
 * the rejected role rather than an accepted read-only one (PRD §20.3 does
 * not grant Staff "View Analytics").
 */
class AnalyticsTenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private const ENDPOINTS = ['sales', 'orders', 'products', 'customers'];

    private function assertAllEndpoints(string $token, int $storeId, int $status): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->withToken($token)
                ->getJson("/api/stores/{$storeId}/analytics/{$endpoint}")
                ->assertStatus($status);
        }
    }

    // ---- RBAC matrix -------------------------------------------------

    public function test_owner_can_reach_every_analytics_endpoint(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->assertAllEndpoints($token, $store->id, 200);
    }

    public function test_store_admin_assigned_to_the_store_can_reach_every_analytics_endpoint(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $this->assertAllEndpoints($token, $store->id, 200);
    }

    public function test_staff_assigned_to_the_store_is_denied_every_analytics_endpoint(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $token = $staff->createToken('t')->plainTextToken;

        // Staff has a store_user row (passes ResolveMerchantStoreContext),
        // so the 403 below comes from AnalyticsPolicy's atLeast(StoreAdmin)
        // check, not from store-access resolution.
        $this->assertAllEndpoints($token, $store->id, 403);
    }

    public function test_store_admin_without_a_store_user_row_is_denied_at_the_middleware_level(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $unassignedStore = Store::factory()->forOrganization($org)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $this->assertAllEndpoints($token, $unassignedStore->id, 403);
    }

    // ---- Tenant isolation ----------------------------------------------

    public function test_a_store_id_belonging_to_another_organization_returns_404(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $orgB = $this->activeOrganization();
        $storeInB = Store::factory()->forOrganization($orgB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        $this->assertAllEndpoints($token, $storeInB->id, 404);
    }

    public function test_spoofed_store_id_and_organization_id_query_parameters_cannot_alter_tenant_scope(): void
    {
        $orgA = $this->activeOrganization();
        $owner = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $token = $owner->createToken('t')->plainTextToken;

        // The route's {store} segment (storeA) is authoritative regardless
        // of a spoofed store_id/organization_id query parameter.
        $response = $this->withToken($token)->getJson(
            "/api/stores/{$storeA->id}/analytics/sales?store_id={$storeB->id}&organization_id={$orgB->id}"
        );

        $response->assertOk()->assertJsonPath('data.range.preset', 'last_30_days');
    }

    public function test_platform_admin_token_cannot_reach_analytics_routes(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();

        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->assertAllEndpoints($token, $store->id, 401);
    }

    public function test_invalid_range_preset_is_rejected_with_a_validation_error(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/stores/{$store->id}/analytics/sales?range=this_quarter")
            ->assertStatus(422);
    }
}
