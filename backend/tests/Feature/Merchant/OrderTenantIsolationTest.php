<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class OrderTenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_an_order_belonging_to_another_organization_cannot_be_read_or_updated(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $orderInB = Order::factory()->forStore($storeB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        // A bare order id (client-supplied, part of the URL) belonging to a
        // different organization's store must never be reachable, even via
        // org A's own, legitimately-resolved store in the URL prefix.
        $this->withToken($token)->getJson("/api/stores/{$storeA->id}/orders/{$orderInB->id}")->assertStatus(404);
        $this->withToken($token)->patchJson("/api/stores/{$storeA->id}/orders/{$orderInB->id}/status", [
            'status' => 'cancelled',
        ])->assertStatus(404);

        $this->assertDatabaseHas('orders', ['id' => $orderInB->id, 'status' => 'pending']);
    }

    public function test_same_organization_unassigned_store_order_is_denied_not_leaked(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $unassignedStore = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($unassignedStore)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        // Same organization, so the store itself resolves (404 would be
        // wrong) — but no store_user row means 403, not access.
        $response = $this->withToken($token)->getJson("/api/stores/{$unassignedStore->id}/orders/{$order->id}");

        $response->assertStatus(403);
    }

    public function test_an_order_belonging_to_a_different_store_in_the_same_organization_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $orderInB = Order::factory()->forStore($storeB)->create();
        $token = $owner->createToken('t')->plainTextToken;

        // A bare order id cannot escape the store scope embedded in the
        // URL, even when the caller (Owner) legitimately has access to
        // BOTH stores — the {store} segment of the URL must match the
        // order's actual store, not just any store the caller can reach.
        $response = $this->withToken($token)->getJson("/api/stores/{$storeA->id}/orders/{$orderInB->id}");

        $response->assertStatus(404);
    }

    public function test_spoofed_store_id_and_organization_id_in_the_status_body_are_ignored(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $otherOrg = $this->activeOrganization();
        $otherStore = Store::factory()->forOrganization($otherOrg)->create();
        $order = Order::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        // The route targets $store; attempt to redirect the mutation to a
        // different store/organization via body fields with the same name.
        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'cancelled',
            'store_id' => $otherStore->id,
            'organization_id' => $otherOrg->id,
        ])->assertOk();

        $order->refresh();
        $this->assertSame($store->id, $order->store_id);
        $this->assertSame($org->id, $order->organization_id);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    public function test_owner_cannot_reach_order_status_route_while_organization_is_pending_but_can_read(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$order->id}")->assertOk();
        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'cancelled',
        ])->assertStatus(403);
    }

    public function test_merchant_token_cannot_reach_order_routes_via_platform_admin_identity(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();

        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$order->id}")->assertStatus(401);
    }
}
