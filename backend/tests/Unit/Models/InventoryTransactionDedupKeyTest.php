<?php

namespace Tests\Unit\Models;

use App\Enums\InventoryTransactionReason;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Database Design 2.6 — verifies the inventory_transactions dedup_key
 * migration's guarantees directly against the schema/database, not just
 * the application code layered on top of it (none of which exists yet —
 * this phase is schema-only).
 */
class InventoryTransactionDedupKeyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Order $order;

    private OrderItem $orderItem;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['status' => 'active']);
        $this->store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($this->store)->create();
        $this->variant = ProductVariant::factory()->forProduct($product)->create();
        $this->order = Order::factory()->forStore($this->store)->create();
        $this->orderItem = OrderItem::factory()->forOrder($this->order)->create();
    }

    private function makePayment(): Payment
    {
        return Payment::factory()->forOrder($this->order)->create();
    }

    private function insertLedgerRow(InventoryTransactionReason $reason, ?int $orderItemId, ?int $paymentId, int $delta = -1): InventoryTransaction
    {
        $transaction = new InventoryTransaction;
        $transaction->product_variant_id = $this->variant->id;
        $transaction->order_item_id = $orderItemId;
        $transaction->payment_id = $paymentId;
        $transaction->delta = $delta;
        $transaction->reason = $reason;
        $transaction->save();

        return $transaction;
    }

    // --- A/B/C: Checkout claim + duplicate rejection + a second attempt allowed ---

    public function test_checkout_p1_can_be_inserted(): void
    {
        $p1 = $this->makePayment();

        $transaction = $this->insertLedgerRow(InventoryTransactionReason::Checkout, $this->orderItem->id, $p1->id);

        $this->assertNotNull($transaction->id);
        $this->assertSame("{$this->orderItem->id}:checkout:{$p1->id}", $transaction->refresh()->dedup_key);
    }

    public function test_duplicate_checkout_p1_is_rejected(): void
    {
        $p1 = $this->makePayment();
        $this->insertLedgerRow(InventoryTransactionReason::Checkout, $this->orderItem->id, $p1->id);

        $this->expectException(QueryException::class);

        $this->insertLedgerRow(InventoryTransactionReason::Checkout, $this->orderItem->id, $p1->id);
    }

    public function test_checkout_p2_is_allowed_after_checkout_p1(): void
    {
        $p1 = $this->makePayment();
        $p2 = $this->makePayment();
        $this->insertLedgerRow(InventoryTransactionReason::Checkout, $this->orderItem->id, $p1->id);

        $transaction = $this->insertLedgerRow(InventoryTransactionReason::Checkout, $this->orderItem->id, $p2->id);

        $this->assertNotNull($transaction->id);
        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    // --- D/E/F: Release + duplicate rejection + a second attempt allowed ---

    public function test_release_p1_is_allowed(): void
    {
        $p1 = $this->makePayment();
        $this->insertLedgerRow(InventoryTransactionReason::Checkout, $this->orderItem->id, $p1->id, -1);

        $transaction = $this->insertLedgerRow(InventoryTransactionReason::Release, $this->orderItem->id, $p1->id, 1);

        $this->assertNotNull($transaction->id);
        $this->assertSame("{$this->orderItem->id}:release:{$p1->id}", $transaction->refresh()->dedup_key);
    }

    public function test_duplicate_release_p1_is_rejected(): void
    {
        $p1 = $this->makePayment();
        $this->insertLedgerRow(InventoryTransactionReason::Release, $this->orderItem->id, $p1->id, 1);

        $this->expectException(QueryException::class);

        $this->insertLedgerRow(InventoryTransactionReason::Release, $this->orderItem->id, $p1->id, 1);
    }

    public function test_release_p2_is_allowed_after_release_p1(): void
    {
        $p1 = $this->makePayment();
        $p2 = $this->makePayment();
        $this->insertLedgerRow(InventoryTransactionReason::Release, $this->orderItem->id, $p1->id, 1);

        $transaction = $this->insertLedgerRow(InventoryTransactionReason::Release, $this->orderItem->id, $p2->id, 1);

        $this->assertNotNull($transaction->id);
        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    // --- G/H: Restock and Adjustment remain unconstrained (payment_id/order_item_id null) ---

    public function test_multiple_restock_rows_remain_allowed(): void
    {
        $this->insertLedgerRow(InventoryTransactionReason::Restock, null, null, 10);
        $this->insertLedgerRow(InventoryTransactionReason::Restock, null, null, 10);
        $this->insertLedgerRow(InventoryTransactionReason::Restock, null, null, 10);

        $this->assertDatabaseCount('inventory_transactions', 3);
        $this->assertSame(3, InventoryTransaction::whereNull('dedup_key')->count());
    }

    public function test_multiple_adjustment_rows_remain_allowed(): void
    {
        $this->insertLedgerRow(InventoryTransactionReason::Adjustment, null, null, -3);
        $this->insertLedgerRow(InventoryTransactionReason::Adjustment, null, null, 5);

        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    // --- I/J/K: CHECK constraint rejects payment-linked reasons with a null id ---

    public function test_checkout_with_null_payment_id_is_rejected_by_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        $this->insertLedgerRow(InventoryTransactionReason::Checkout, $this->orderItem->id, null);
    }

    public function test_release_with_null_payment_id_is_rejected_by_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        $this->insertLedgerRow(InventoryTransactionReason::Release, $this->orderItem->id, null, 1);
    }

    public function test_refund_with_null_payment_id_is_rejected_by_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        $this->insertLedgerRow(InventoryTransactionReason::Refund, $this->orderItem->id, null, 1);
    }

    public function test_checkout_with_null_order_item_id_is_rejected_by_check_constraint(): void
    {
        $p1 = $this->makePayment();

        $this->expectException(QueryException::class);

        $this->insertLedgerRow(InventoryTransactionReason::Checkout, null, $p1->id);
    }
}
