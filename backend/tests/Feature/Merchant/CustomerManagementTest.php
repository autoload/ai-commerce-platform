<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    // ---- List --------------------------------------------------------

    public function test_owner_can_list_customers_ordered_by_created_at_descending(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $first = Customer::factory()->forStore($store)->create(['name' => 'First Created']);
        $first->created_at = now()->subDays(2);
        $first->save();
        $second = Customer::factory()->forStore($store)->create(['name' => 'Second Created']);
        $second->created_at = now()->subDay();
        $second->save();
        $third = Customer::factory()->forStore($store)->create(['name' => 'Third Created']);
        $third->created_at = now();
        $third->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Third Created', 'Second Created', 'First Created'], $names);
    }

    public function test_list_only_includes_customers_for_the_current_store(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        Customer::factory()->forStore($storeA)->create(['name' => 'In A']);
        Customer::factory()->forStore($storeB)->create(['name' => 'In B']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$storeA->id}/customers");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'In A');
    }

    public function test_list_is_paginated_at_fifteen_per_page(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Customer::factory()->forStore($store)->count(17)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers");

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_search_matches_customer_name(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Customer::factory()->forStore($store)->create(['name' => 'Jane Appleseed', 'email' => 'jane@example.com']);
        Customer::factory()->forStore($store)->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers?search=Apple");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Jane Appleseed');
    }

    public function test_search_matches_customer_email(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Customer::factory()->forStore($store)->create(['name' => 'Jane Appleseed', 'email' => 'jane@example.com']);
        Customer::factory()->forStore($store)->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers?search=bob@example.com");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bob Builder');
    }

    public function test_search_with_no_match_returns_empty(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Customer::factory()->forStore($store)->create(['name' => 'Jane Appleseed', 'email' => 'jane@example.com']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers?search=nonexistent-term");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- Detail --------------------------------------------------------

    public function test_owner_can_view_customer_detail(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create([
            'name' => 'Jane Appleseed',
            'email' => 'jane@example.com',
            'phone' => '555-0100',
        ]);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.store_id', $store->id)
            ->assertJsonPath('data.name', 'Jane Appleseed')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.phone', '555-0100')
            ->assertJsonPath('data.order_count', 0)
            ->assertJsonPath('data.total_spent', '0.00');

        $json = $response->json('data');
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('stripe_customer_id', $json);
        $this->assertArrayNotHasKey('email_verified_at', $json);
        $this->assertArrayNotHasKey('organization_id', $json);
        $this->assertArrayNotHasKey('deleted_at', $json);
        $this->assertArrayNotHasKey('addresses', $json);
        $this->assertArrayNotHasKey('payment_methods', $json);
        $this->assertArrayNotHasKey('orders', $json);
        $this->assertArrayNotHasKey('refunds', $json);
    }

    public function test_nonexistent_customer_returns_404(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/999999")->assertStatus(404);
    }

    // ---- RBAC (read access) ---------------------------------------------

    public function test_staff_can_list_and_view_customers(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $customer = Customer::factory()->forStore($store)->create();
        $token = $staff->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/customers")->assertOk();
        $this->withToken($token)->getJson("/api/stores/{$store->id}/customers/{$customer->id}")->assertOk();
    }

    // ---- Authentication --------------------------------------------------

    public function test_unauthenticated_requests_are_rejected_on_every_customer_route(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();

        $this->getJson("/api/stores/{$store->id}/customers")->assertStatus(401);
        $this->getJson("/api/stores/{$store->id}/customers/{$customer->id}")->assertStatus(401);
    }
}
