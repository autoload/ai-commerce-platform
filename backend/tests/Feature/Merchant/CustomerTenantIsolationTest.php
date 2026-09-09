<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\Customer;
use App\Models\PlatformAdmin;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class CustomerTenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_a_customer_id_belonging_to_another_organization_cannot_be_read(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $customerInB = Customer::factory()->forStore($storeB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$storeA->id}/customers/{$customerInB->id}")->assertStatus(404);
    }

    public function test_a_customer_id_belonging_to_another_store_in_the_same_organization_returns_404(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $customerInB = Customer::factory()->forStore($storeB)->create();
        $token = $owner->createToken('t')->plainTextToken;

        // A bare customer id (client-supplied, part of the URL) cannot
        // escape the store scope embedded in the URL, even when the
        // caller (Owner) legitimately has access to BOTH stores.
        $this->withToken($token)->getJson("/api/stores/{$storeA->id}/customers/{$customerInB->id}")->assertStatus(404);
    }

    public function test_same_organization_store_admin_without_assignment_is_denied_not_leaked(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $unassignedStore = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($unassignedStore)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        // Same organization, so the store itself resolves (404 would be
        // wrong here) — but no store_user row means 403, not access.
        $listResponse = $this->withToken($token)->getJson("/api/stores/{$unassignedStore->id}/customers");
        $listResponse->assertStatus(403);

        $detailResponse = $this->withToken($token)->getJson("/api/stores/{$unassignedStore->id}/customers/{$customer->id}");
        $detailResponse->assertStatus(403);
    }

    public function test_spoofed_store_id_and_organization_id_query_parameters_cannot_alter_tenant_scope(): void
    {
        $orgA = $this->activeOrganization();
        $owner = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        Customer::factory()->forStore($storeA)->create(['name' => 'Customer In A']);
        Customer::factory()->forStore($storeB)->create(['name' => 'Customer In B']);
        $token = $owner->createToken('t')->plainTextToken;

        // The route's {store} segment (storeA) is authoritative — a
        // spoofed store_id/organization_id query parameter attempting to
        // redirect the query to storeB's data must be ignored entirely.
        $response = $this->withToken($token)->getJson(
            "/api/stores/{$storeA->id}/customers?store_id={$storeB->id}&organization_id={$orgB->id}"
        );

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Customer In A');
    }

    public function test_merchant_token_cannot_reach_customer_routes_via_platform_admin_identity(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/customers")->assertStatus(401);
        $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}")->assertStatus(401);
    }

    public function test_customer_guard_token_cannot_reach_merchant_customer_routes(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('customer')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/customers")->assertStatus(401);
        $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}")->assertStatus(401);
    }
}
