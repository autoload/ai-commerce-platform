<?php

namespace Tests\Feature\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\OrganizationStatus;
use App\Enums\StoreStatus;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogProductTest extends TestCase
{
    use RefreshDatabase;

    private function activeStore(): Store
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();

        return Store::factory()->forOrganization($org)->create();
    }

    private function activeProduct(Store $store, array $attributes = []): Product
    {
        $product = Product::factory()->forStore($store)->create($attributes);
        $product->status = CatalogStatus::Active;
        $product->save();

        return $product;
    }

    private function activeVariant(Product $product, array $attributes = []): ProductVariant
    {
        $variant = ProductVariant::factory()->forProduct($product)->create($attributes);
        $variant->status = CatalogStatus::Active;
        $variant->save();

        return $variant;
    }

    // --- A. Store access ---------------------------------------------

    public function test_active_store_exposes_its_catalog(): void
    {
        $store = $this->activeStore();
        $product = $this->activeProduct($store);
        $this->activeVariant($product);

        $response = $this->getJson("/api/shop/stores/{$store->id}/products");

        $response->assertOk()->assertJsonPath('data.0.id', $product->id);
    }

    public function test_unknown_store_returns_404(): void
    {
        $response = $this->getJson('/api/shop/stores/999999/products');

        $response->assertStatus(404);
    }

    public function test_inactive_store_does_not_expose_its_catalog(): void
    {
        $store = $this->activeStore();
        $store->status = StoreStatus::Inactive;
        $store->save();
        $product = $this->activeProduct($store);
        $this->activeVariant($product);

        $response = $this->getJson("/api/shop/stores/{$store->id}/products");

        $response->assertStatus(404);
    }

    public function test_store_belonging_to_an_inactive_organization_does_not_expose_its_catalog(): void
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();
        $store = Store::factory()->forOrganization($org)->create();
        $product = $this->activeProduct($store);
        $this->activeVariant($product);

        $org->status = OrganizationStatus::Suspended;
        $org->save();

        $response = $this->getJson("/api/shop/stores/{$store->id}/products");

        $response->assertStatus(404);
    }

    // --- B. Product isolation ------------------------------------------

    public function test_store_a_does_not_return_store_b_products(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $productA = $this->activeProduct($storeA);
        $this->activeVariant($productA);
        $productB = $this->activeProduct($storeB);
        $this->activeVariant($productB);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($productA->id));
        $this->assertFalse($ids->contains($productB->id));
    }

    public function test_store_a_product_detail_cannot_retrieve_store_b_product(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $productB = $this->activeProduct($storeB);
        $this->activeVariant($productB);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products/{$productB->id}");

        $response->assertStatus(404);
    }

    public function test_product_belonging_to_another_store_returns_404_not_leaked(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $productB = $this->activeProduct($storeB, ['name' => 'Store B Secret Product']);
        $this->activeVariant($productB);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products/{$productB->id}");

        $response->assertStatus(404);
        $this->assertStringNotContainsString('Store B Secret Product', $response->getContent());
    }

    // --- C. Nested relationship isolation -------------------------------

    public function test_category_data_cannot_leak_across_store_boundaries(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $categoryA = Category::factory()->forStore($storeA)->create(['name' => 'Category A']);
        $categoryB = Category::factory()->forStore($storeB)->create(['name' => 'Category B']);
        $productA = $this->activeProduct($storeA, ['category_id' => $categoryA->id]);
        $this->activeVariant($productA);
        $productB = $this->activeProduct($storeB, ['category_id' => $categoryB->id]);
        $this->activeVariant($productB);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products/{$productA->id}");

        $response->assertOk()
            ->assertJsonPath('data.category.id', $categoryA->id)
            ->assertJsonPath('data.category.name', 'Category A');
        $this->assertStringNotContainsString('Category B', $response->getContent());
    }

    public function test_product_options_cannot_leak_across_store_boundaries(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $productA = $this->activeProduct($storeA);
        $this->activeVariant($productA);
        ProductOption::factory()->forProduct($productA)->create(['name' => 'Color']);
        $productB = $this->activeProduct($storeB);
        $this->activeVariant($productB);
        ProductOption::factory()->forProduct($productB)->create(['name' => 'Size']);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products/{$productA->id}");

        $response->assertOk()->assertJsonPath('data.options.0.name', 'Color');
        $this->assertStringNotContainsString('"name":"Size"', $response->getContent());
    }

    public function test_option_values_cannot_leak_across_store_boundaries(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $productA = $this->activeProduct($storeA);
        $this->activeVariant($productA);
        $optionA = ProductOption::factory()->forProduct($productA)->create();
        ProductOptionValue::factory()->forOption($optionA)->create(['value' => 'Red']);

        $productB = $this->activeProduct($storeB);
        $this->activeVariant($productB);
        $optionB = ProductOption::factory()->forProduct($productB)->create();
        ProductOptionValue::factory()->forOption($optionB)->create(['value' => 'Blue']);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products/{$productA->id}");

        $response->assertOk()->assertJsonPath('data.options.0.values.0.value', 'Red');
        $this->assertStringNotContainsString('Blue', $response->getContent());
    }

    public function test_variants_cannot_leak_across_store_boundaries(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $productA = $this->activeProduct($storeA);
        $this->activeVariant($productA, ['sku' => 'STORE-A-SKU']);
        $productB = $this->activeProduct($storeB);
        $this->activeVariant($productB, ['sku' => 'STORE-B-SKU']);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products/{$productA->id}");

        $response->assertOk()->assertJsonPath('data.variants.0.sku', 'STORE-A-SKU');
        $this->assertStringNotContainsString('STORE-B-SKU', $response->getContent());
    }

    public function test_product_images_cannot_leak_across_store_boundaries(): void
    {
        $storeA = $this->activeStore();
        $storeB = $this->activeStore();
        $productA = $this->activeProduct($storeA);
        $this->activeVariant($productA);
        ProductImage::factory()->forProduct($productA)->create(['url' => 'https://example.com/a.jpg']);
        $productB = $this->activeProduct($storeB);
        $this->activeVariant($productB);
        ProductImage::factory()->forProduct($productB)->create(['url' => 'https://example.com/b.jpg']);

        $response = $this->getJson("/api/shop/stores/{$storeA->id}/products/{$productA->id}");

        $response->assertOk()->assertJsonPath('data.images.0.url', 'https://example.com/a.jpg');
        $this->assertStringNotContainsString('b.jpg', $response->getContent());
    }

    // --- D. Response correctness ----------------------------------------

    public function test_response_contains_expected_customer_safe_fields(): void
    {
        $store = $this->activeStore();
        $product = $this->activeProduct($store);
        $this->activeVariant($product, ['sku' => 'ABC-123', 'price' => 19.99]);

        $response = $this->getJson("/api/shop/stores/{$store->id}/products/{$product->id}");

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'id', 'name', 'slug', 'description', 'category',
                'images', 'options',
                'variants' => [['id', 'sku', 'price', 'compare_at_price', 'options']],
            ],
        ])->assertJsonPath('data.variants.0.sku', 'ABC-123');
    }

    public function test_response_does_not_expose_internal_admin_only_fields(): void
    {
        $store = $this->activeStore();
        $product = $this->activeProduct($store);
        $this->activeVariant($product);

        $response = $this->getJson("/api/shop/stores/{$store->id}/products/{$product->id}");

        $response->assertOk();
        $json = $response->json('data');
        $this->assertArrayNotHasKey('store_id', $json);
        $this->assertArrayNotHasKey('organization_id', $json);
        $this->assertArrayNotHasKey('status', $json);
        $this->assertArrayNotHasKey('metadata', $json);
        $this->assertArrayNotHasKey('created_at', $json);
        $this->assertArrayNotHasKey('updated_at', $json);
    }

    public function test_draft_products_are_not_listed_or_reachable(): void
    {
        $store = $this->activeStore();
        $product = Product::factory()->forStore($store)->create(); // defaults to draft
        ProductVariant::factory()->forProduct($product)->create();

        $listResponse = $this->getJson("/api/shop/stores/{$store->id}/products");
        $listResponse->assertOk()->assertJsonCount(0, 'data');

        $showResponse = $this->getJson("/api/shop/stores/{$store->id}/products/{$product->id}");
        $showResponse->assertStatus(404);
    }

    public function test_empty_catalog_behaves_correctly(): void
    {
        $store = $this->activeStore();

        $response = $this->getJson("/api/shop/stores/{$store->id}/products");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_product_detail_returns_correct_nested_catalog_data(): void
    {
        $store = $this->activeStore();
        $category = Category::factory()->forStore($store)->create(['name' => 'Widgets']);
        $product = $this->activeProduct($store, ['category_id' => $category->id]);
        $variant = $this->activeVariant($product, ['sku' => 'WID-1', 'price' => 9.5]);
        $option = ProductOption::factory()->forProduct($product)->create(['name' => 'Color']);
        $value = ProductOptionValue::factory()->forOption($option)->create(['value' => 'Red']);
        $variant->optionValues()->attach($value->id);
        ProductImage::factory()->forProduct($product)->create(['url' => 'https://example.com/wid.jpg', 'is_primary' => true]);

        $response = $this->getJson("/api/shop/stores/{$store->id}/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.category.name', 'Widgets')
            ->assertJsonPath('data.images.0.url', 'https://example.com/wid.jpg')
            ->assertJsonPath('data.options.0.name', 'Color')
            ->assertJsonPath('data.options.0.values.0.value', 'Red')
            ->assertJsonPath('data.variants.0.sku', 'WID-1')
            ->assertJsonPath('data.variants.0.options.0.option', 'Color')
            ->assertJsonPath('data.variants.0.options.0.value', 'Red');
    }

    // --- E. Performance --------------------------------------------------

    public function test_listing_does_not_grow_query_count_with_more_products(): void
    {
        $store = $this->activeStore();
        $product = $this->activeProduct($store);
        $this->activeVariant($product);

        DB::enableQueryLog();
        $this->getJson("/api/shop/stores/{$store->id}/products")->assertOk();
        $oneProductQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        // Logging is disabled while creating fixtures — otherwise the
        // fixture-creation queries themselves (inserts/updates, unrelated
        // to the endpoint under test) would inflate this count and produce
        // a false N+1 failure.
        for ($i = 0; $i < 4; $i++) {
            $extra = $this->activeProduct($store);
            $this->activeVariant($extra);
        }

        DB::enableQueryLog();
        $this->getJson("/api/shop/stores/{$store->id}/products")->assertOk();
        $fiveProductQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $oneProductQueryCount,
            $fiveProductQueryCount,
            'Query count should not scale with the number of products (N+1 regression).'
        );
    }

    // --- F. Regression is covered by re-running the full suite, not here.
}
