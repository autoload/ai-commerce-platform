<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class InventoryTenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_a_variant_id_belonging_to_another_organization_cannot_be_read_or_adjusted(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $productInB = Product::factory()->forStore($storeB)->create();
        $variantInB = ProductVariant::factory()->forProduct($productInB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        // A bare variant id (client-supplied, part of the URL) belonging to
        // a different organization's store must never be reachable, even
        // via org A's own, legitimately-resolved store in the URL prefix.
        $this->withToken($token)->getJson("/api/stores/{$storeA->id}/variants/{$variantInB->id}/inventory")->assertStatus(404);
        $this->withToken($token)->postJson("/api/stores/{$storeA->id}/variants/{$variantInB->id}/inventory/adjust", [
            'delta' => 10,
            'reason' => 'restock',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('inventory', ['product_variant_id' => $variantInB->id]);
    }

    public function test_same_organization_unassigned_store_variant_is_denied_not_leaked(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $unassignedStore = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($unassignedStore)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        // Same organization, so the store itself resolves (404 would be
        // wrong) — but no store_user row means 403, not access.
        $response = $this->withToken($token)->getJson("/api/stores/{$unassignedStore->id}/variants/{$variant->id}/inventory");

        $response->assertStatus(403);
    }

    public function test_a_variant_id_belonging_to_a_different_store_in_the_same_organization_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $productInB = Product::factory()->forStore($storeB)->create();
        $variantInB = ProductVariant::factory()->forProduct($productInB)->create();
        $token = $owner->createToken('t')->plainTextToken;

        // A bare variant_id cannot escape the store scope embedded in the
        // URL, even when the caller (Owner) legitimately has access to
        // BOTH stores — the {store} segment of the URL must match the
        // variant's actual store, not just any store the caller can reach.
        $response = $this->withToken($token)->getJson("/api/stores/{$storeA->id}/variants/{$variantInB->id}/inventory");

        $response->assertStatus(404);
    }

    public function test_client_supplied_variant_id_in_the_adjust_body_is_ignored(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        // Two separate products, each with its one default variant — a
        // single product can only ever have one variant while
        // option_signature is always '' (unique(product_id, option_signature)).
        $productA = Product::factory()->forStore($store)->create();
        $productB = Product::factory()->forStore($store)->create();
        $variantA = ProductVariant::factory()->forProduct($productA)->create();
        $variantB = ProductVariant::factory()->forProduct($productB)->create();
        $token = $owner->createToken('t')->plainTextToken;

        // The route targets variantA; attempt to redirect the mutation to
        // variantB via a body field with the same name.
        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variantA->id}/inventory/adjust", [
            'delta' => 7,
            'reason' => 'restock',
            'variant_id' => $variantB->id,
            'product_variant_id' => $variantB->id,
        ])->assertOk();

        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variantA->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseMissing('inventory', ['product_variant_id' => $variantB->id]);
    }

    public function test_owner_cannot_reach_inventory_routes_while_organization_is_pending_for_mutation_but_can_read(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory")->assertOk();
        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 5,
            'reason' => 'restock',
        ])->assertStatus(403);
    }

    public function test_merchant_token_cannot_reach_inventory_routes_via_platform_admin_identity(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory")->assertStatus(401);
    }
}
