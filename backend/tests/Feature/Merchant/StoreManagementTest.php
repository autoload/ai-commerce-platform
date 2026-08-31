<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_owner_can_create_a_store_in_an_active_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/stores', ['name' => 'Vancouver Store']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Vancouver Store')
            ->assertJsonPath('data.slug', 'vancouver-store')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.organization_id', $org->id);

        $this->assertDatabaseHas('stores', ['organization_id' => $org->id, 'name' => 'Vancouver Store']);
    }

    public function test_store_creation_generates_unique_slug_on_collision(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        Store::factory()->forOrganization($org)->create(['slug' => 'main-store']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/stores', ['name' => 'Main Store']);

        $response->assertCreated()->assertJsonPath('data.slug', 'main-store-2');
    }

    public function test_store_creation_requires_name(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/stores', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_owner_cannot_create_a_store_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/stores', ['name' => 'Store']);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('stores', ['organization_id' => $org->id]);
    }

    public function test_store_admin_cannot_create_a_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/stores', ['name' => 'Store']);

        $response->assertStatus(403);
    }

    public function test_staff_cannot_create_a_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $token = $staff->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/stores', ['name' => 'Store']);

        $response->assertStatus(403);
    }

    public function test_owner_lists_every_store_in_their_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        Store::factory()->forOrganization($org)->count(3)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stores');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_store_admin_lists_only_their_assigned_stores(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assigned = Store::factory()->forOrganization($org)->create();
        Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assigned);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stores');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($assigned->id, $response->json('data.0.id'));
    }

    public function test_owner_can_view_any_store_in_their_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}");

        $response->assertOk()->assertJsonPath('data.id', $store->id);
    }

    public function test_store_admin_without_store_user_row_gets_403_for_same_org_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}");

        $response->assertStatus(403);
    }

    public function test_cross_organization_store_lookup_returns_404(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $orgB = $this->activeOrganization();
        $storeInB = Store::factory()->forOrganization($orgB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$storeInB->id}");

        $response->assertStatus(404);
    }

    public function test_owner_can_update_store_name_and_status(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}", [
            'name' => 'Renamed Store',
            'status' => 'inactive',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Renamed Store')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'name' => 'Renamed Store', 'status' => 'inactive']);
    }

    public function test_store_update_rejects_invalid_status(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}", ['status' => 'archived']);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_store_admin_cannot_update_a_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}", ['name' => 'New Name']);

        $response->assertStatus(403);
    }

    public function test_owner_cannot_update_a_store_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}", ['name' => 'New Name']);

        $response->assertStatus(403);
    }

    public function test_owner_can_soft_delete_a_store(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/stores/{$store->id}");

        $response->assertOk();
        $this->assertSoftDeleted('stores', ['id' => $store->id]);
    }

    public function test_deleted_store_no_longer_appears_in_listing(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/stores/{$store->id}")->assertOk();
        $response = $this->withToken($token)->getJson('/api/stores');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_store_admin_cannot_delete_a_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/stores/{$store->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('stores', ['id' => $store->id, 'deleted_at' => null]);
    }

    public function test_unauthenticated_requests_are_rejected_on_every_store_route(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();

        $this->getJson('/api/stores')->assertStatus(401);
        $this->postJson('/api/stores', ['name' => 'x'])->assertStatus(401);
        $this->getJson("/api/stores/{$store->id}")->assertStatus(401);
        $this->patchJson("/api/stores/{$store->id}", ['name' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/stores/{$store->id}")->assertStatus(401);
    }
}
