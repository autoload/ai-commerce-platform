<?php

namespace Tests\Feature\Customer;

use App\Enums\OrganizationStatus;
use App\Enums\StoreStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    private function activeStore(): Store
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();

        return Store::factory()->forOrganization($org)->create();
    }

    public function test_customer_can_register_against_an_active_store(): void
    {
        $store = $this->activeStore();

        $response = $this->postJson('/api/customers/auth/register', [
            'store_id' => $store->id,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'customer' => ['id', 'name', 'email', 'store_id']])
            ->assertJsonPath('customer.email', 'jamie@example.com')
            ->assertJsonPath('customer.store_id', $store->id);

        $this->assertDatabaseHas('customers', ['store_id' => $store->id, 'email' => 'jamie@example.com']);
    }

    public function test_registration_rejects_duplicate_email_within_the_same_store(): void
    {
        $store = $this->activeStore();
        Customer::factory()->forStore($store)->create(['email' => 'dupe@example.com']);

        $response = $this->postJson('/api/customers/auth/register', [
            'store_id' => $store->id,
            'name' => 'Someone Else',
            'email' => 'dupe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_registration_allows_the_same_email_across_different_stores(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        Customer::factory()->forStore($storeA)->create(['email' => 'shared@example.com']);

        $response = $this->postJson('/api/customers/auth/register', [
            'store_id' => $storeB->id,
            'name' => 'Different Store',
            'email' => 'shared@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', ['store_id' => $storeB->id, 'email' => 'shared@example.com']);
    }

    public function test_registration_rejects_an_inactive_store(): void
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();
        $store = Store::factory()->forOrganization($org)->create();
        $store->status = StoreStatus::Inactive;
        $store->save();

        $response = $this->postJson('/api/customers/auth/register', [
            'store_id' => $store->id,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['store_id']);
    }

    public function test_registration_rejects_a_store_whose_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $store = Store::factory()->forOrganization($org)->create();

        $response = $this->postJson('/api/customers/auth/register', [
            'store_id' => $store->id,
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('customers', ['email' => 'jamie@example.com']);
    }

    public function test_customer_can_login_with_valid_credentials(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/customers/auth/login', [
            'store_id' => $store->id,
            'email' => $customer->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'customer' => ['id', 'name', 'email']])
            ->assertJsonPath('customer.id', $customer->id);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/customers/auth/login', [
            'store_id' => $store->id,
            'email' => $customer->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $store = $this->activeStore();

        $response = $this->postJson('/api/customers/auth/login', [
            'store_id' => $store->id,
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_fails_when_email_belongs_to_a_different_store(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $customer = Customer::factory()->forStore($storeA)->create([
            'email' => 'jamie@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/customers/auth/login', [
            'store_id' => $storeB->id,
            'email' => $customer->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_requires_store_id_email_and_password(): void
    {
        $response = $this->postJson('/api/customers/auth/login', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['store_id', 'email', 'password']);
    }

    public function test_valid_customer_token_can_access_protected_route(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/customers/auth/me');

        $response->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('store.id', $store->id);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/customers/auth/me');

        $response->assertStatus(401);
    }

    public function test_customer_can_logout(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/customers/auth/logout');

        $response->assertOk();
    }

    public function test_revoked_token_is_rejected_after_logout(): void
    {
        $store = $this->activeStore();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/customers/auth/logout')->assertOk();

        // Laravel's RequestGuard caches its resolved user for the lifetime
        // of the booted Application, so within a single test method a second
        // simulated request would otherwise see the guard's stale cache
        // instead of re-authenticating — this cannot happen on real traffic,
        // where every request boots a fresh Application. Forgetting the
        // cached guard here simulates the fresh Application a real second
        // request would get. (Same gotcha already documented for Platform
        // Admin and merchant auth.)
        Auth::forgetGuards();

        $response = $this->withToken($token)->getJson('/api/customers/auth/me');

        $response->assertStatus(401);
    }
}
