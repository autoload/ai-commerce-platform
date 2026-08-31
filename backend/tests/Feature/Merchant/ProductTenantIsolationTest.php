<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\Category;
use App\Models\PlatformAdmin;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class ProductTenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_client_supplied_store_id_in_the_body_cannot_override_the_url_scoped_store(): void
    {
        $orgA = $this->activeOrganization();
        $owner = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $storeB = Store::factory()->forOrganization($orgA)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$storeA->id}/products", [
            'name' => 'Spoofed Product',
            'sku' => 'SKU-1',
            'price' => 10,
            'store_id' => $storeB->id,
            'organization_id' => 999,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['name' => 'Spoofed Product', 'store_id' => $storeA->id]);
        $this->assertDatabaseMissing('products', ['name' => 'Spoofed Product', 'store_id' => $storeB->id]);
    }

    public function test_a_product_id_belonging_to_another_organization_cannot_be_read_updated_or_deleted(): void
    {
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $productInB = Product::factory()->forStore($storeB)->create();
        $token = $ownerA->createToken('t')->plainTextToken;

        // Attempting to reach org B's store at all is already blocked by
        // tenant.merchant.store (proven in StoreTenantIsolationTest) — this
        // asserts the same holds one level deeper, through the product
        // routes nested under it.
        $this->withToken($token)->getJson("/api/stores/{$storeA->id}/products/{$productInB->id}")->assertStatus(404);
        $this->withToken($token)->patchJson("/api/stores/{$storeA->id}/products/{$productInB->id}", ['name' => 'x'])->assertStatus(404);
        $this->withToken($token)->deleteJson("/api/stores/{$storeA->id}/products/{$productInB->id}")->assertStatus(404);

        $this->assertDatabaseHas('products', ['id' => $productInB->id, 'deleted_at' => null]);
    }

    public function test_same_organization_store_admin_without_assignment_is_denied_not_leaked(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $unassignedStore = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($unassignedStore)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        // Same organization, so the store itself resolves (404 would be
        // wrong here) — but no store_user row means 403, not access.
        $response = $this->withToken($token)->getJson("/api/stores/{$unassignedStore->id}/products/{$product->id}");

        $response->assertStatus(403);
    }

    public function test_a_category_belonging_to_another_store_cannot_be_referenced_on_product_creation(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        // Deliberately the SAME organization, a DIFFERENT store — the
        // stronger case, proving category_id validation is scoped to the
        // verified store specifically, not merely to the organization.
        $storeB = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $categoryInStoreB = new Category;
        $categoryInStoreB->organization_id = $org->id;
        $categoryInStoreB->store_id = $storeB->id;
        $categoryInStoreB->name = 'Store B Category';
        $categoryInStoreB->slug = 'store-b-category';
        $categoryInStoreB->save();

        $response = $this->withToken($token)->postJson("/api/stores/{$storeA->id}/products", [
            'name' => 'Cross-Store Category Product',
            'sku' => 'SKU-CAT-1',
            'price' => 10,
            'category_id' => $categoryInStoreB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->assertDatabaseMissing('products', ['name' => 'Cross-Store Category Product']);
    }

    public function test_merchant_token_cannot_reach_product_routes_via_platform_admin_identity(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();

        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/products")->assertStatus(401);
    }
}
