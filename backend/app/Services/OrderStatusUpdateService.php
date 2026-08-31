<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Support\MerchantOrderStatusTransitions;
use Illuminate\Support\Facades\DB;

/**
 * The single mutation path for merchant-triggered order status changes.
 * Mirrors InventoryAdjustmentService's proven shape: lock the row inside a
 * transaction, re-validate against the freshly-read current state (never
 * the possibly-stale value the caller/Policy saw), apply, commit.
 *
 * This directly satisfies database-design.md's own concurrency
 * requirement ("Order cancellation vs. payment webhook" — the order's
 * status transition happens inside a transaction that locks the orders
 * row and validates the current status before applying a transition; the
 * loser of any race is rejected rather than silently overwriting).
 *
 * Only ever called with a target status from MerchantOrderStatusTransitions'
 * whitelist (enforced one layer up, in OrderStatusUpdateRequest) — this
 * service does not itself distinguish merchant-legal target values from
 * webhook-only ones; it only validates the current->target edge.
 */
class OrderStatusUpdateService
{
    /**
     * @throws InvalidOrderTransitionException if the transition is not a
     *                                         merchant-allowed edge from the order's current status
     */
    public function transition(Order $order, OrderStatus $to): Order
    {
        return DB::transaction(function () use ($order, $to) {
            /** @var Order $locked */
            $locked = Order::where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! MerchantOrderStatusTransitions::isAllowed($locked->status, $to)) {
                throw new InvalidOrderTransitionException($locked->status, $to);
            }

            $locked->status = $to;

            if ($to === OrderStatus::Cancelled) {
                $locked->cancelled_at = now();
            }

            $locked->save();

            return $locked;
        });
    }
}
