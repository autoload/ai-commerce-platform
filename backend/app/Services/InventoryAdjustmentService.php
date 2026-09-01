<?php

namespace App\Services;

use App\Enums\InventoryTransactionReason;
use App\Exceptions\InsufficientInventoryException;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single, locked path for every inventory quantity mutation in this
 * application — merchant-driven (Restock/Adjustment) and system-driven
 * (Checkout/Release/Refund, from checkout/webhooks). Nothing else is
 * permitted to write quantity_on_hand directly; Inventory's own fillable
 * list is empty specifically to force every write through here.
 *
 * Per CLAUDE.md's required correctness property: transaction + row lock +
 * quantity update + ledger insert, atomically. The reason a caller may use
 * is NOT restricted here — restriction to Restock/Adjustment for merchant
 * requests is enforced one layer up, in InventoryAdjustRequest's validation.
 *
 * $orderItem/$payment are supplied only for Checkout/Release/Refund
 * claims (database-design.md §9) — they populate inventory_transactions'
 * order_id/order_item_id/payment_id, which the CHECK constraint requires
 * to be non-null for exactly those three reasons and which the generated
 * dedup_key column keys on. Restock/Adjustment leave both null, unchanged
 * from before this parameter existed.
 */
class InventoryAdjustmentService
{
    /**
     * @throws InsufficientInventoryException if the resulting quantity would be negative
     */
    public function adjust(
        ProductVariant $variant,
        int $delta,
        InventoryTransactionReason $reason,
        ?string $note,
        ?User $actor,
        ?OrderItem $orderItem = null,
        ?Payment $payment = null,
    ): Inventory {
        return DB::transaction(function () use ($variant, $delta, $reason, $note, $actor, $orderItem, $payment) {
            // Ensure a row exists before attempting to lock it — inventory
            // rows are lazily materialized on first adjustment, not created
            // eagerly when a variant is created. insertOrIgnore is a raw
            // query-builder call (not Eloquent mass assignment, which
            // Inventory's empty $fillable would silently reject) and is
            // safe under concurrency: if two requests race to materialize
            // the same never-adjusted variant, the second insert is a
            // harmless no-op and both proceed to lock the same row below.
            DB::table('inventory')->insertOrIgnore([
                'product_variant_id' => $variant->id,
                'quantity_on_hand' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /** @var Inventory $inventory */
            $inventory = Inventory::where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            $newQuantity = $inventory->quantity_on_hand + $delta;

            if ($newQuantity < 0) {
                throw new InsufficientInventoryException($inventory->quantity_on_hand, $delta);
            }

            $inventory->quantity_on_hand = $newQuantity;
            $inventory->save();

            $transaction = new InventoryTransaction;
            $transaction->product_variant_id = $variant->id;
            $transaction->order_id = $orderItem?->order_id;
            $transaction->order_item_id = $orderItem?->id;
            $transaction->payment_id = $payment?->id;
            $transaction->delta = $delta;
            $transaction->reason = $reason;
            $transaction->note = $note;
            $transaction->created_by_user_id = $actor?->id;
            $transaction->save();

            return $inventory;
        });
    }
}
