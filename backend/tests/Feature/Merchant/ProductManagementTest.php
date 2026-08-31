<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_owner_can_create_a_product_with_a_default_variant(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Blue Widget',
            'sku' => 'WIDGET-BLUE',
            'price' => 19.99,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Blue Widget')
            ->assertJsonPath('data.slug', 'blue-widget')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.store_id', $store->id)
            ->assertJsonPath('data.variant.sku', 'WIDGET-BLUE')
            ->assertJsonPath('data.variant.status', 'draft');

        $this->assertDatabaseHas('products', ['store_id' => $store->id, 'name' => 'Blue Widget']);
    }

    public function test_product_creation_generates_unique_slug_on_collision(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Product::factory()->forStore($store)->create(['slug' => 'widget']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Widget',
            'sku' => 'WIDGET-2',
            'price' => 9.99,
        ]);

        $response->assertCreated()->assertJsonPath('data.slug', 'widget-2');
    }

    public function test_default_variant_is_automatically_created_with_empty_option_signature(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Gadget',
            'sku' => 'GADGET-1',
            'price' => 29.99,
        ]);

        $response->assertCreated();
        $productId = $response->json('data.id');

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $productId,
            'sku' => 'GADGET-1',
            'option_signature' => '',
        ]);
        $this->assertSame(1, ProductVariant::where('product_id', $productId)->count());
    }

    public function test_product_and_default_variant_are_created_atomically_on_duplicate_sku(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $existingProduct = Product::factory()->forStore($store)->create();
        $variant = new ProductVariant;
        $variant->organization_id = $org->id;
        $variant->store_id = $store->id;
        $variant->product_id = $existingProduct->id;
        $variant->sku = 'TAKEN-SKU';
        $variant->price = 10;
        $variant->option_signature = '';
        $variant->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Another Product',
            'sku' => 'TAKEN-SKU',
            'price' => 15,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['sku']);
        $this->assertDatabaseMissing('products', ['name' => 'Another Product']);
    }

    /**
     * Unlike the duplicate-SKU test above — which is rejected by
     * ProductCreateRequest's validation before store()'s DB::transaction()
     * body ever runs — this test forces a genuine failure *inside* the
     * transaction, after the Product row has already been inserted but
     * before the ProductVariant insert completes. A model `saving` hook is
     * used to simulate the failure (e.g. a real DB-level error under a
     * race condition) without modifying ProductController at all, proving
     * DB::transaction() actually rolls back both rows, not just that
     * validation can reject a request beforehand.
     */
    public function test_creation_transaction_rolls_back_both_product_and_variant_on_a_genuine_insert_failure(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        ProductVariant::saving(function (ProductVariant $variant) {
            if ($variant->sku === 'FORCE-TRANSACTION-FAILURE') {
                throw new RuntimeException('Simulated failure to verify transaction rollback.');
            }
        });

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Should Not Persist',
            'sku' => 'FORCE-TRANSACTION-FAILURE',
            'price' => 10,
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseMissing('products', ['name' => 'Should Not Persist']);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'FORCE-TRANSACTION-FAILURE']);
    }

    public function test_product_creation_requires_name_sku_and_price(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'sku', 'price']);
    }

    public function test_owner_cannot_create_a_product_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Widget',
            'sku' => 'SKU-1',
            'price' => 5,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('products', ['store_id' => $store->id]);
    }

    public function test_staff_cannot_create_a_product(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $token = $staff->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Widget',
            'sku' => 'SKU-1',
            'price' => 5,
        ]);

        $response->assertStatus(403);
    }

    public function test_owner_lists_every_product_in_a_store(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Product::factory()->forStore($store)->count(3)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/products");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_staff_can_list_products_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        Product::factory()->forStore($store)->count(2)->create();
        $token = $staff->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/products");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_owner_can_retrieve_a_single_product(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/products/{$product->id}");

        $response->assertOk()->assertJsonPath('data.id', $product->id);
    }

    public function test_store_admin_without_store_user_row_gets_403_for_same_org_product(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/products/{$product->id}");

        $response->assertStatus(403);
    }

    public function test_owner_can_update_product_and_variant_fields(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = new ProductVariant;
        $variant->organization_id = $org->id;
        $variant->store_id = $store->id;
        $variant->product_id = $product->id;
        $variant->sku = 'OLD-SKU';
        $variant->price = 10;
        $variant->option_signature = '';
        $variant->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/products/{$product->id}", [
            'name' => 'Renamed Product',
            'status' => 'active',
            'sku' => 'NEW-SKU',
            'price' => 25.50,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Renamed Product')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.variant.sku', 'NEW-SKU');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Renamed Product', 'status' => 'active']);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'NEW-SKU']);
    }

    public function test_product_update_rejects_invalid_status(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/products/{$product->id}", [
            'status' => 'deprecated',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_staff_cannot_update_a_product(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $product = Product::factory()->forStore($store)->create();
        $token = $staff->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/products/{$product->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(403);
    }

    public function test_owner_cannot_update_a_product_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/products/{$product->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(403);
    }

    public function test_owner_can_soft_delete_a_product(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/stores/{$store->id}/products/{$product->id}");

        $response->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_deleted_product_no_longer_appears_in_listing(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/stores/{$store->id}/products/{$product->id}")->assertOk();
        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/products");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_deleted_product_returns_404_on_direct_access(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/stores/{$store->id}/products/{$product->id}")->assertOk();
        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/products/{$product->id}");

        $response->assertStatus(404);
    }

    public function test_deleting_a_product_does_not_delete_its_variant_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = new ProductVariant;
        $variant->organization_id = $org->id;
        $variant->store_id = $store->id;
        $variant->product_id = $product->id;
        $variant->sku = 'SKU-KEEP';
        $variant->price = 10;
        $variant->option_signature = '';
        $variant->save();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/stores/{$store->id}/products/{$product->id}")->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'deleted_at' => null]);
    }

    public function test_staff_cannot_delete_a_product(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $product = Product::factory()->forStore($store)->create();
        $token = $staff->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/stores/{$store->id}/products/{$product->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_unauthenticated_requests_are_rejected_on_every_product_route(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();

        $this->getJson("/api/stores/{$store->id}/products")->assertStatus(401);
        $this->postJson("/api/stores/{$store->id}/products", ['name' => 'x'])->assertStatus(401);
        $this->getJson("/api/stores/{$store->id}/products/{$product->id}")->assertStatus(401);
        $this->patchJson("/api/stores/{$store->id}/products/{$product->id}", ['name' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/stores/{$store->id}/products/{$product->id}")->assertStatus(401);
    }
}
