<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\Category;
use App\Models\PlatformAdmin;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class CategoryTenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_client_supplied_store_id_in_the_body_cannot_override_the_url_scoped_store(): void
    {
        $orgA = $this->activeOrganization();
        $owner = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $storeB = Store::factory()->forOrganization($orgA)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$storeA->id}/categories", [
            'name' => 'Spoofed Category',
            'store_id' => $storeB->id,
            'organization_id' => 999,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('categories', ['name' => 'Spoofed Category', 'store_id' => $storeA->id]);
        $this->assertDatabaseMissing('categories', ['name' => 'Spoofed Category', 'store_id' => $storeB->id]);
    }

    public function test_a_category_id_belonging_to_another_organization_cannot_be_read_updated_or_deleted(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $categoryInB = Category::factory()->forStore($storeB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$storeA->id}/categories/{$categoryInB->id}")->assertStatus(404);
        $this->withToken($token)->patchJson("/api/stores/{$storeA->id}/categories/{$categoryInB->id}", ['name' => 'x'])->assertStatus(404);
        $this->withToken($token)->deleteJson("/api/stores/{$storeA->id}/categories/{$categoryInB->id}")->assertStatus(404);

        $this->assertDatabaseHas('categories', ['id' => $categoryInB->id, 'deleted_at' => null]);
    }

    public function test_a_category_id_belonging_to_another_store_in_the_same_organization_returns_404(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $categoryInB = Category::factory()->forStore($storeB)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$storeA->id}/categories/{$categoryInB->id}")->assertStatus(404);
    }

    public function test_same_organization_store_admin_without_assignment_is_denied_not_leaked(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $unassignedStore = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($unassignedStore)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        // Same organization, so the store itself resolves (404 would be
        // wrong here) — but no store_user row means 403, not access.
        $response = $this->withToken($token)->getJson("/api/stores/{$unassignedStore->id}/categories/{$category->id}");

        $response->assertStatus(403);
    }

    public function test_merchant_token_cannot_reach_category_routes_via_platform_admin_identity(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();

        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/categories")->assertStatus(401);
    }
}
