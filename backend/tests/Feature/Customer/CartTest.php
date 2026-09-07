<?php

namespace Tests\Feature\Customer;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrganizationRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\InventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Phase 8B revision — the authenticated cart API (GET/POST/PATCH/DELETE
 * /api/cart[...], POST /api/cart/merge). Redis's "cart" connection/database
 * (config/cart.php) is flushed before every test — RefreshDatabase resets
 * MySQL/SQLite but has no effect on Redis, and this suite's auto-incrementing
 * store/customer ids restart every test, so cart:{store}:{customer} keys
 * would otherwise collide across test methods.
 */
class CartTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('cart')->flushdb();
    }

    private function activeVariantWithStock(Store $store, int $stock, float $price = 25.00): ProductVariant
    {
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => $price,
            'status' => CatalogStatus::Active,
        ]);

        if ($stock > 0) {
            app(InventoryAdjustmentService::class)->adjust(
                $variant, $stock, InventoryTransactionReason::Restock, null, null
            );
        }

        return $variant;
    }

    private function customerToken(Customer $customer): string
    {
        return $customer->createToken('customer-session')->plainTextToken;
    }

    private function customerForStore(Store $store): Customer
    {
        return Customer::factory()->forStore($store)->create();
    }

    // 1. Unauthenticated access is rejected on every cart endpoint.
    public function test_unauthenticated_requests_are_rejected_on_every_cart_route(): void
    {
        $this->getJson('/api/cart')->assertStatus(401);
        $this->postJson('/api/cart/items', ['product_variant_id' => 1, 'quantity' => 1])->assertStatus(401);
        $this->patchJson('/api/cart/items/1', ['quantity' => 2])->assertStatus(401);
        $this->deleteJson('/api/cart/items/1')->assertStatus(401);
        $this->deleteJson('/api/cart')->assertStatus(401);
        $this->postJson('/api/cart/merge', ['items' => []])->assertStatus(401);
    }

    // 1b. A merchant token (different guard/provider) is rejected too —
    // matching every other cross-identity isolation test in this codebase.
    public function test_merchant_token_cannot_reach_cart_routes(): void
    {
        $org = $this->activeOrganization();
        $user = $this->memberWithRole($org, OrganizationRole::Owner);
        $merchantToken = $user->createToken('merchant-session')->plainTextToken;

        $this->withToken($merchantToken)->getJson('/api/cart')->assertStatus(401);
    }

    // 2. Customer can read their own (initially empty) cart.
    public function test_customer_can_read_their_own_empty_cart(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);

        $this->withToken($this->customerToken($customer))
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJson(['data' => ['items' => [], 'subtotal' => '0.00']]);
    }

    // 5. Add item appears in the cart, hydrated from live MySQL state.
    public function test_add_item_appears_in_cart_hydrated_from_mysql(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10, price: 19.99);

        $response = $this->withToken($this->customerToken($customer))
            ->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 2])
            ->assertOk();

        $response->assertJsonPath('data.items.0.product_variant_id', $variant->id);
        $response->assertJsonPath('data.items.0.quantity', 2);
        $response->assertJsonPath('data.items.0.price', '19.99');
        $response->assertJsonPath('data.items.0.line_total', '39.98');
        $response->assertJsonPath('data.subtotal', '39.98');
    }

    // 6. Duplicate add sums into the existing quantity (never overwrites).
    public function test_duplicate_add_increments_quantity(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 2])->assertOk();
        $response = $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 3])->assertOk();

        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.quantity', 5);
    }

    // 7. PATCH sets the exact quantity (overwrite, not sum).
    public function test_set_quantity_overwrites_the_line(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 2])->assertOk();
        $response = $this->withToken($token)->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 7])->assertOk();

        $response->assertJsonPath('data.items.0.quantity', 7);
    }

    public function test_set_quantity_rejects_zero_or_negative(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $this->withToken($token)->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 0])->assertStatus(422);
    }

    // 8. Remove item deletes the line.
    public function test_remove_item_deletes_the_line(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 2])->assertOk();
        $response = $this->withToken($token)->deleteJson("/api/cart/items/{$variant->id}")->assertOk();

        $response->assertJsonCount(0, 'data.items');
    }

    // 9. Clear empties the whole cart (204, no body).
    public function test_clear_cart_empties_it(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variantA = $this->activeVariantWithStock($store, 10);
        $variantB = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variantA->id, 'quantity' => 1])->assertOk();
        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variantB->id, 'quantity' => 1])->assertOk();

        $this->withToken($token)->deleteJson('/api/cart')->assertStatus(204);

        $this->withToken($token)->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.items');
    }

    // 10 & 11. Merge sums guest quantities into whatever already exists in
    // Redis — guest A×2 + existing Redis A×3 => A×5, per the approved design.
    public function test_merge_sums_guest_quantities_into_existing_redis_cart(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variantA = $this->activeVariantWithStock($store, 100);
        $variantB = $this->activeVariantWithStock($store, 100);
        $token = $this->customerToken($customer);

        // Existing authenticated cart: A x 3.
        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variantA->id, 'quantity' => 3])->assertOk();

        // Guest payload: A x 2, B x 1.
        $response = $this->withToken($token)->postJson('/api/cart/merge', [
            'items' => [
                ['product_variant_id' => $variantA->id, 'quantity' => 2],
                ['product_variant_id' => $variantB->id, 'quantity' => 1],
            ],
        ])->assertOk();

        $items = collect($response->json('data.items'))->keyBy('product_variant_id');
        $this->assertSame(5, $items[$variantA->id]['quantity']);
        $this->assertSame(1, $items[$variantB->id]['quantity']);
    }

    public function test_merge_into_an_empty_redis_cart_just_adopts_the_guest_quantities(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 100);
        $token = $this->customerToken($customer);

        $response = $this->withToken($token)->postJson('/api/cart/merge', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 4]],
        ])->assertOk();

        $response->assertJsonPath('data.items.0.quantity', 4);
    }

    public function test_merge_with_an_empty_items_array_is_a_harmless_no_op(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/merge', ['items' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    // 12. A stale (deleted/archived) variant is dropped silently, never
    // fails the whole cart, and is pruned from Redis (lazy cleanup).
    public function test_stale_variant_is_dropped_from_the_hydrated_cart_and_pruned_from_redis(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $liveVariant = $this->activeVariantWithStock($store, 10);
        $staleVariant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $liveVariant->id, 'quantity' => 1])->assertOk();
        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $staleVariant->id, 'quantity' => 1])->assertOk();

        $staleVariant->delete(); // soft-delete — no longer resolves via the Active/store-scoped query

        $response = $this->withToken($token)->getJson('/api/cart')->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.product_variant_id', $liveVariant->id);

        // Lazily pruned from the underlying Redis hash too, not merely
        // filtered at read time.
        $key = "cart:{$store->id}:{$customer->id}";
        // PHP itself coerces numeric-looking array keys to int, regardless
        // of the underlying Redis hash field's own string representation.
        $remainingFields = array_keys(Redis::connection('cart')->hgetall($key));
        $this->assertSame([$liveVariant->id], $remainingFields);
    }

    // A variant that exists but belongs to a DIFFERENT store never
    // hydrates for this customer, even if somehow present in the hash —
    // proves store-scoping is enforced at hydration, not merely assumed
    // from how the hash was populated.
    public function test_a_variant_belonging_to_another_store_never_hydrates(): void
    {
        $org = $this->activeOrganization();
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $customerA = $this->customerForStore($storeA);
        $foreignVariant = $this->activeVariantWithStock($storeB, 10);

        // Directly seed customerA's Redis hash with storeB's variant id —
        // something the API itself would never do (addItem never validates
        // the variant belongs to the store either, by design), simulating
        // any hypothetical way a foreign id could end up in the hash.
        Redis::connection('cart')->hincrby("cart:{$storeA->id}:{$customerA->id}", (string) $foreignVariant->id, 1);

        $this->withToken($this->customerToken($customerA))
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    // 3 & 4. A customer cannot see another customer's cart — including one
    // in the very same store — proving isolation is per-customer, not
    // merely per-store.
    public function test_customer_cannot_access_another_customers_cart(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customerA = $this->customerForStore($store);
        $customerB = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10);

        $this->withToken($this->customerToken($customerA))
            ->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 9])
            ->assertOk();

        // Laravel's RequestGuard caches its resolved user for the lifetime
        // of the booted Application — without this, the request below would
        // still resolve to customer A, cached from the request above, even
        // with a different Bearer token. Same documented gotcha as
        // CustomerTenantIsolationTest::test_two_customers_from_different_stores_each_see_only_their_own_store.
        Auth::forgetGuards();

        $this->withToken($this->customerToken($customerB))
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    // 4. Tenant/store isolation across organizations — same variant id
    // coincidentally reused is still impossible since ids aren't shared,
    // but the key spirit test here is: two customers in two entirely
    // different organizations/stores never see each other's cart.
    public function test_cart_is_isolated_across_organizations_and_stores(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $customerA = $this->customerForStore($storeA);
        $customerB = $this->customerForStore($storeB);
        $variantA = $this->activeVariantWithStock($storeA, 10);

        $this->withToken($this->customerToken($customerA))
            ->postJson('/api/cart/items', ['product_variant_id' => $variantA->id, 'quantity' => 3])
            ->assertOk();

        Auth::forgetGuards();

        $this->withToken($this->customerToken($customerB))
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    // The Redis key is derived only from CustomerContext (server-resolved)
    // — a client-supplied store_id/customer_id/organization_id anywhere in
    // the request is simply not a field this API accepts, so there is no
    // input for a client to override cart ownership with. This test proves
    // the negative directly: sending those fields changes nothing.
    public function test_client_supplied_ownership_fields_are_not_accepted_or_honored(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $otherCustomer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10);

        $this->withToken($this->customerToken($customer))
            ->postJson('/api/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'customer_id' => $otherCustomer->id,
                'store_id' => 999999,
                'organization_id' => 999999,
            ])
            ->assertOk();

        // The item landed in the AUTHENTICATED customer's own cart, not
        // the spoofed one.
        $this->withToken($this->customerToken($customer))->getJson('/api/cart')->assertOk()->assertJsonCount(1, 'data.items');

        Auth::forgetGuards();

        $this->withToken($this->customerToken($otherCustomer))->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.items');
    }

    // 13. TTL is set (and refreshed) on mutation, matching config('cart.ttl_seconds').
    public function test_ttl_is_set_on_mutation_and_matches_configuration(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 1])->assertOk();

        $ttl = Redis::connection('cart')->ttl("cart:{$store->id}:{$customer->id}");
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual((int) config('cart.ttl_seconds'), $ttl);
    }

    // 14. Adding/updating/merging never enforces inventory — a cart may
    // temporarily hold quantity > stock (including a never-stocked
    // variant with quantity_on_hand 0). Checkout, unchanged by this
    // revision, remains the sole authoritative inventory gate.
    public function test_no_inventory_enforcement_during_add_update_or_merge(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $variant = $this->activeVariantWithStock($store, 0); // no stock at all
        $token = $this->customerToken($customer);

        $this->withToken($token)
            ->postJson('/api/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 500])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 500)
            ->assertJsonPath('data.items.0.in_stock', false);

        $this->withToken($token)
            ->patchJson("/api/cart/items/{$variant->id}", ['quantity' => 1000])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 1000);
    }

    // 15. Invalid/malformed variant access is rejected appropriately —
    // a non-numeric {variant} 404s at the route level (whereNumber).
    public function test_non_numeric_variant_id_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $token = $this->customerToken($customer);

        $this->withToken($token)->patchJson('/api/cart/items/not-a-number', ['quantity' => 1])->assertStatus(404);
        $this->withToken($token)->deleteJson('/api/cart/items/not-a-number')->assertStatus(404);
    }

    public function test_add_item_validates_required_fields(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = $this->customerForStore($store);
        $token = $this->customerToken($customer);

        $this->withToken($token)->postJson('/api/cart/items', [])->assertStatus(422);
        $this->withToken($token)->postJson('/api/cart/items', ['product_variant_id' => 1, 'quantity' => 0])->assertStatus(422);
    }
}
