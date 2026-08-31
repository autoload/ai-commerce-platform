<?php

namespace Tests\Feature\Customer;

use App\Enums\OrganizationStatus;
use App\Enums\StoreStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CustomerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function activeStore(): Store
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();

        return Store::factory()->forOrganization($org)->create();
    }

    public function test_me_resolves_the_authenticated_customers_own_store_and_organization(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/auth/me');

        $response->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer.store_id', $store->id)
            ->assertJsonPath('store.id', $store->id);
    }

    public function test_two_customers_from_different_stores_each_see_only_their_own_store(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $customerA = Customer::factory()->forStore($storeA)->create();
        $customerB = Customer::factory()->forStore($storeB)->create();

        $tokenA = $customerA->createToken('t')->plainTextToken;
        $tokenB = $customerB->createToken('t')->plainTextToken;

        $this->withToken($tokenA)->getJson('/api/customers/auth/me')
            ->assertOk()
            ->assertJsonPath('store.id', $storeA->id);

        // Laravel's RequestGuard caches its resolved user for the lifetime
        // of the booted Application — without this, the second call below
        // would still see customer A cached from the request above, even
        // though a real second request (different token) would not. Same
        // gotcha already documented for the revoked-token tests.
        Auth::forgetGuards();

        $this->withToken($tokenB)->getJson('/api/customers/auth/me')
            ->assertOk()
            ->assertJsonPath('store.id', $storeB->id);
    }

    public function test_client_supplied_store_id_query_param_cannot_override_the_resolved_context(): void
    {
        $ownStore = $this->activeStore();
        $otherStore = $this->activeStore();
        $customer = Customer::factory()->forStore($ownStore)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/auth/me?store_id='.$otherStore->id);

        $response->assertOk()->assertJsonPath('store.id', $ownStore->id);
    }

    public function test_client_supplied_store_id_header_cannot_override_the_resolved_context(): void
    {
        $ownStore = $this->activeStore();
        $otherStore = $this->activeStore();
        $customer = Customer::factory()->forStore($ownStore)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeaders(['X-Store-Id' => (string) $otherStore->id])
            ->getJson('/api/customers/auth/me');

        $response->assertOk()->assertJsonPath('store.id', $ownStore->id);
    }

    public function test_customer_cannot_authenticate_against_an_organization_that_has_since_been_suspended(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $store->organization->status = OrganizationStatus::Suspended;
        $store->organization->save();

        $response = $this->withToken($token)->getJson('/api/customers/auth/me');

        $response->assertStatus(403);
    }

    public function test_customer_cannot_authenticate_against_a_store_that_has_since_been_deactivated(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $store->status = StoreStatus::Inactive;
        $store->save();

        $response = $this->withToken($token)->getJson('/api/customers/auth/me');

        $response->assertStatus(403);
    }

    public function test_customer_token_cannot_reach_merchant_routes(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_customer_token_cannot_reach_platform_admin_routes(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/platform/auth/me');

        $response->assertStatus(401);
    }

    public function test_merchant_token_cannot_reach_customer_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/auth/me');

        $response->assertStatus(401);
    }

    public function test_platform_admin_token_cannot_reach_customer_routes(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/auth/me');

        $response->assertStatus(401);
    }
}
