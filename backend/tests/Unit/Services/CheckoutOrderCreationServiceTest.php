<?php

namespace Tests\Unit\Services;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Exceptions\CustomerStoreMismatchException;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\ProductVariantUnavailableException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\CheckoutOrderCreationService;
use App\Services\InventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Phase 3 STEP 3A — CheckoutOrderCreationService, the atomic
 * Order/OrderItems/OrderAddress/Payment + inventory-claim transaction
 * (database-design.md §9/§13). No controller/route exists yet — this
 * calls the service directly, the same way InventoryManagementTest calls
 * through a route for InventoryAdjustmentService's HTTP-facing behavior.
 */
class CheckoutOrderCreationServiceTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private function service(): CheckoutOrderCreationService
    {
        return app(CheckoutOrderCreationService::class);
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

    /**
     * @return array<string, string>
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

    public function test_creates_order_items_address_payment_and_claims_inventory_atomically(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 20.00);

        $order = $this->service()->createPendingOrder(
            $customer,
            $store,
            [['variant' => $variant, 'quantity' => 3]],
            $this->shippingAddress(),
            'pi_test_123',
        );

        $this->assertSame('pending', $order->status->value);
        $this->assertEquals(60.00, (float) $order->total);
        $this->assertCount(1, $order->items);
        $this->assertCount(1, $order->addresses);
        $this->assertCount(1, $order->payments);
        $this->assertSame('pi_test_123', $order->payments->first()->stripe_payment_intent_id);
        $this->assertSame('requires_payment', $order->payments->first()->status->value);

        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'payment_id' => $order->payments->first()->id,
            'reason' => 'checkout',
            'delta' => -3,
        ]);
    }

    public function test_insufficient_inventory_rolls_back_the_entire_order(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 2, 20.00);

        try {
            $this->service()->createPendingOrder(
                $customer,
                $store,
                [['variant' => $variant, 'quantity' => 5]],
                $this->shippingAddress(),
                'pi_test_456',
            );
            $this->fail('Expected InsufficientInventoryException.');
        } catch (InsufficientInventoryException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 2]);
    }

    public function test_second_line_items_failure_rolls_back_the_first_line_items_claim_too(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variantA = $this->activeVariantWithStock($store, 10, 10.00);
        $variantB = $this->activeVariantWithStock($store, 1, 10.00);

        try {
            $this->service()->createPendingOrder(
                $customer,
                $store,
                [
                    ['variant' => $variantA, 'quantity' => 5],
                    ['variant' => $variantB, 'quantity' => 5],
                ],
                $this->shippingAddress(),
                'pi_test_789',
            );
            $this->fail('Expected InsufficientInventoryException.');
        } catch (InsufficientInventoryException) {
            // expected
        }

        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variantA->id, 'quantity_on_hand' => 10]);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variantB->id, 'quantity_on_hand' => 1]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_rejects_a_variant_belonging_to_a_different_store(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $otherStore = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($otherStore, 10);

        try {
            $this->service()->createPendingOrder(
                $customer, $store, [['variant' => $variant, 'quantity' => 1]], $this->shippingAddress(), 'pi_x',
            );
            $this->fail('Expected ProductVariantUnavailableException.');
        } catch (ProductVariantUnavailableException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_rejects_a_customer_belonging_to_a_different_store(): void
    {
        $org = $this->activeOrganization();
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($storeA)->create();
        // Belongs to storeB (the store actually passed to the service)
        // so the only possible rejection cause is the customer mismatch,
        // not a variant/store mismatch.
        $variant = $this->activeVariantWithStock($storeB, 10);

        try {
            $this->service()->createPendingOrder(
                $customer, $storeB, [['variant' => $variant, 'quantity' => 1]], $this->shippingAddress(), 'pi_mismatch',
            );
            $this->fail('Expected CustomerStoreMismatchException.');
        } catch (CustomerStoreMismatchException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_addresses', 0);
        // The seed restock transaction from activeVariantWithStock() is
        // expected to exist — only a checkout claim must be absent.
        $this->assertDatabaseMissing('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'reason' => 'checkout',
        ]);
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 10]);
    }

    public function test_rejects_an_inactive_variant(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10);
        $variant->status = CatalogStatus::Archived;
        $variant->save();

        $this->expectException(ProductVariantUnavailableException::class);

        $this->service()->createPendingOrder(
            $customer, $store, [['variant' => $variant, 'quantity' => 1]], $this->shippingAddress(), 'pi_x',
        );
    }

    public function test_repeat_submission_with_same_idempotency_key_and_payload_returns_the_existing_order(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 15.00);

        $first = $this->service()->createPendingOrder(
            $customer, $store, [['variant' => $variant, 'quantity' => 2]], $this->shippingAddress(),
            'pi_a', 'idem-key-1', 'hash-1',
        );

        $second = $this->service()->createPendingOrder(
            $customer, $store, [['variant' => $variant, 'quantity' => 2]], $this->shippingAddress(),
            'pi_b', 'idem-key-1', 'hash-1',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        // The claim must not be repeated on the idempotent replay.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 8]);
    }

    public function test_repeat_submission_with_the_same_key_but_a_different_payload_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 15.00);

        $this->service()->createPendingOrder(
            $customer, $store, [['variant' => $variant, 'quantity' => 2]], $this->shippingAddress(),
            'pi_a', 'idem-key-2', 'hash-a',
        );

        $this->expectException(IdempotencyKeyConflictException::class);

        $this->service()->createPendingOrder(
            $customer, $store, [['variant' => $variant, 'quantity' => 1]], $this->shippingAddress(),
            'pi_c', 'idem-key-2', 'hash-b',
        );
    }

    public function test_inventory_claim_uses_row_locking(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $variant = $this->activeVariantWithStock($store, 10, 15.00);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service()->createPendingOrder(
            $customer, $store, [['variant' => $variant, 'quantity' => 1]], $this->shippingAddress(), 'pi_lock',
        );

        $lockingQueryFound = collect($queries)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'inventory') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertTrue($lockingQueryFound, 'Expected a SELECT ... FOR UPDATE query against inventory during checkout claim.');
    }
}
