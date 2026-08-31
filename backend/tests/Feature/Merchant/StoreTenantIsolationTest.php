<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_supplied_organization_id_in_the_body_is_ignored_on_create(): void
    {
        $orgA = $this->activeOrganization();
        $owner = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $orgB = $this->activeOrganization();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/stores', [
            'name' => 'Spoofed Store',
            'organization_id' => $orgB->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('stores', ['name' => 'Spoofed Store', 'organization_id' => $orgA->id]);
        $this->assertDatabaseMissing('stores', ['name' => 'Spoofed Store', 'organization_id' => $orgB->id]);
    }

    public function test_a_store_id_belonging_to_another_organization_cannot_be_read_updated_or_deleted(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $orgB = $this->activeOrganization();
        $storeInB = Store::factory()->forOrganization($orgB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$storeInB->id}")->assertStatus(404);
        $this->withToken($token)->patchJson("/api/stores/{$storeInB->id}", ['name' => 'x'])->assertStatus(404);
        $this->withToken($token)->deleteJson("/api/stores/{$storeInB->id}")->assertStatus(404);

        $this->assertDatabaseHas('stores', ['id' => $storeInB->id, 'name' => $storeInB->name, 'deleted_at' => null]);
    }

    public function test_tenant_context_store_reflects_the_resolved_verified_store(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.organization_id', $org->id);
    }

    public function test_merchant_token_cannot_reach_store_routes_via_platform_admin_identity(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->withToken($token)->getJson('/api/stores')->assertStatus(401);
    }

    private function activeOrganization(): Organization
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();

        return $org;
    }

    private function memberWithRole(Organization $org, OrganizationRole $role): User
    {
        $user = User::factory()->create();

        $membership = new OrganizationUser;
        $membership->organization_id = $org->id;
        $membership->user_id = $user->id;
        $membership->role = $role;
        $membership->save();

        return $user;
    }
}
