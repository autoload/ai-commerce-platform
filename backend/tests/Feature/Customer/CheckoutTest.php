<?php

namespace Tests\Feature\Customer;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\InventoryAdjustmentService;
use App\Services\StripePaymentIntentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Stripe\ErrorObject;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\IdempotencyException;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\Doubles\FakePaymentIntentGateway;
use Tests\TestCase;

/**
 * STEP 3B — checkout orchestration (POST /api/checkout). Uses a fake
 * StripePaymentIntentGateway bound into the container in place of
 * StripeApiPaymentIntentGateway — no real Stripe credentials or network
 * traffic anywhere in this suite.
 *
 * A "customer/store mismatch defense-in-depth via HTTP" case (matching the
 * STEP 3A audit's CustomerStoreMismatchException) is deliberately not
 * included here: ResolveCustomerContext derives the Store solely from the
 * authenticated customer's own store_id column, and this endpoint accepts
 * no store_id field at all — there is no HTTP-reachable input that could
 * ever produce a mismatch, and the middleware re-binds CustomerContext
 * fresh on every request, so pre-seeding a mismatched instance before
 * dispatch is immediately overwritten. That mechanism is already proven
 * directly at the service level by
 * CheckoutOrderCreationServiceTest::test_rejects_a_customer_belonging_to_a_different_store
 * (STEP 3A) — duplicating it here would require bypassing the very
 * middleware that makes it unreachable, which would test something false.
 */
class CheckoutTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private function fakeGateway(): FakePaymentIntentGateway
    {
        $fake = new FakePaymentIntentGateway;
        $this->app->instance(StripePaymentIntentGateway::class, $fake);

        return $fake;
    }

    private function activeVariantWithStock(Store $store, int $stock, float $price = 25.00): ProductVariant
    {
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => $price,
            'status' => CatalogStatus::Active,
        ]);

        app(InventoryAdjustmentService::class)->adjust(
            $variant, $stock, InventoryTransactionReason::Restock, null, null
        );

        return $variant;
    }

    private function customerToken(Customer $customer): string
    {
        return $customer->createToken('customer-session')->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function shippingAddress(): array
    {
        return [
            'recipient_name' => 'Jane Doe',
            'line1' => '123 Main St',
            'city' => 'Springfield',
            'state' => 'IL',
            'postal_code' => '62701',
            'country' => 'US',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  ?array<string, mixed>  $address
     */
    private function checkout(string $token, array $items, ?array $address = null, ?string $idempotencyKey = 'idem-key-1'): TestResponse
    {
        $request = $this->withToken($token);

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        return $request->postJson('/api/checkout', [
            'items' => $items,
            'shipping_address' => $address ?? $this->shippingAddress(),
        ]);
    }

    public function test_happy_path_creates_order_and_returns_client_secret(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 3],
        ], null, 'idem-happy-1');

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending');
        $this->assertEquals(60.00, (float) $response->json('data.total'));
        $this->assertNotEmpty($response->json('payment.client_secret'));
        $this->assertNotEmpty($response->json('payment.stripe_payment_intent_id'));

        $this->assertCount(1, $fake->calls);
        $this->assertSame(6000, $fake->calls[0]['params']['amount']);
        $this->assertSame('usd', $fake->calls[0]['params']['currency']);
        $this->assertSame(['card'], $fake->calls[0]['params']['payment_method_types']);
        $this->assertSame((string) $org->id, $fake->calls[0]['params']['metadata']['organization_id']);
        $this->assertSame((string) $store->id, $fake->calls[0]['params']['metadata']['store_id']);
        $this->assertSame((string) $customer->id, $fake->calls[0]['params']['metadata']['customer_id']);
        $this->assertSame('idem-happy-1', $fake->calls[0]['idempotency_key']);

        $this->assertDatabaseHas('orders', [
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'idempotency_key' => 'idem-happy-1',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $response->json('data.id'),
            'stripe_payment_intent_id' => $response->json('payment.stripe_payment_intent_id'),
            'status' => 'requires_payment',
        ]);
        $this->assertDatabaseHas('order_items', ['product_variant_id' => $variant->id, 'quantity' => 3]);
        $this->assertDatabaseHas('order_addresses', ['recipient_name' => 'Jane Doe']);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'reason' => 'checkout',
            'delta' => -3,
        ]);
    }

    public function test_missing_idempotency_key_is_rejected_before_calling_stripe(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ], null, null);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_empty_cart_is_rejected_before_calling_stripe(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, []);

        $response->assertStatus(422);
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_malformed_quantity_is_rejected_before_calling_stripe(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 0],
        ]);

        $response->assertStatus(422);
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_nonexistent_variant_is_rejected_before_calling_stripe(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => 999999999, 'quantity' => 1],
        ]);

        $response->assertStatus(422);
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cross_store_variant_is_rejected_before_calling_stripe(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $otherStore = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($otherStore, 10);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ]);

        $response->assertStatus(422);
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_insufficient_inventory_rolls_back_after_stripe_has_already_been_called(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 2, 20.00);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 5],
        ]);

        $response->assertStatus(422);
        $this->assertCount(1, $fake->calls);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_addresses', 0);
        $this->assertDatabaseMissing('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'reason' => 'checkout',
        ]);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 2]);
    }

    public function test_repeat_request_same_key_same_payload_returns_the_existing_order(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 15.00);
        $token = $this->customerToken($customer);
        $items = [['product_variant_id' => $variant->id, 'quantity' => 2]];

        $first = $this->checkout($token, $items, null, 'idem-repeat-1');
        $first->assertStatus(201);

        $second = $this->checkout($token, $items, null, 'idem-repeat-1');
        $second->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertNotEmpty($second->json('payment.client_secret'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('order_items', 1);
        // Stripe is called both times (its own idempotency is what
        // absorbs the duplicate on the real API) — only the local write
        // must not be duplicated.
        $this->assertCount(2, $fake->calls);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 8]);
    }

    public function test_repeat_request_same_key_different_payload_is_rejected(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 15.00);
        $token = $this->customerToken($customer);

        $first = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 2],
        ], null, 'idem-conflict-1');
        $first->assertStatus(201);

        $second = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 3],
        ], null, 'idem-conflict-1');

        $second->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'id' => $first->json('data.id'),
            'total' => 30.00,
        ]);
    }

    public function test_stripe_concurrent_idempotency_key_conflict_is_mapped_to_409(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $fake->willThrow(IdempotencyException::factory(
            'Keys for idempotent requests can only be used with the same parameters they were first used with, or if there is an existing request in progress.',
            400,
            null,
            null,
            null,
            ErrorObject::CODE_IDEMPOTENCY_KEY_IN_USE,
        ));

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_generic_stripe_api_failure_is_mapped_without_leaking_details(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $fake->willThrow(ApiConnectionException::factory('Could not connect to Stripe over the internal test network.'));

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ]);

        $response->assertStatus(502);
        $this->assertDatabaseCount('orders', 0);
        $this->assertStringNotContainsString('Could not connect to Stripe', $response->getContent());
    }

    public function test_duplicate_variant_lines_are_merged_into_one_order_item(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 10.00);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 2],
            ['product_variant_id' => $variant->id, 'quantity' => 3],
        ], null, 'idem-dup-1');

        $response->assertStatus(201);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame(5, $response->json('data.items.0.quantity'));
        $this->assertEquals(50.00, (float) $response->json('data.total'));

        // Stripe was told the combined amount, not the first line alone.
        $this->assertCount(1, $fake->calls);
        $this->assertSame(5000, $fake->calls[0]['params']['amount']);

        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', ['product_variant_id' => $variant->id, 'quantity' => 5]);
        // One restock seed row (from activeVariantWithStock) plus exactly
        // one checkout claim — not two, proving the two request lines
        // were merged before CheckoutOrderCreationService ever saw them.
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'reason' => 'checkout',
            'delta' => -5,
        ]);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 5]);
    }

    public function test_equivalent_duplicate_and_pre_merged_carts_share_the_same_idempotency_payload(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 10.00);
        $token = $this->customerToken($customer);

        $first = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 5],
        ], null, 'idem-equiv-1');
        $first->assertStatus(201);

        // A second, differently-shaped request expressing the identical
        // cart (2 + 3 = 5, same variant) under the same idempotency key
        // must be recognized as a replay of the same payload, not a
        // conflict — proving normalization happens before the payload
        // hash is computed, not just before persistence.
        $second = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 2],
            ['product_variant_id' => $variant->id, 'quantity' => 3],
        ], null, 'idem-equiv-1');

        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 5]);
    }

    public function test_whitespace_only_idempotency_key_is_rejected(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ], null, '   ');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_idempotency_key_surrounding_whitespace_is_trimmed_and_used_consistently(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 10.00);
        $token = $this->customerToken($customer);

        $response = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ], null, '  padded-key-1  ');

        $response->assertStatus(201);

        // Trimmed value is what Stripe received as the idempotency key.
        $this->assertSame('padded-key-1', $fake->calls[0]['idempotency_key']);

        // Trimmed value is what's persisted for orders.idempotency_key.
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'idempotency_key' => 'padded-key-1',
        ]);

        // A later request using the already-trimmed key directly must be
        // recognized as the same idempotent request as the padded one.
        $second = $this->checkout($token, [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ], null, 'padded-key-1');

        $second->assertStatus(200);
        $this->assertSame($response->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }
}
