<?php

namespace App\Support;

use App\Enums\OrderStatus;

/**
 * Pure transition-validity helper — deliberately knows nothing about RBAC
 * (that's OrderPolicy::updateStatus) and nothing about the webhook/system
 * side of the order state machine (pending -> paid, {paid,...} -> refunded).
 * This table encodes ONLY the edges a merchant may trigger through
 * PATCH /orders/{order}/status, per database-design.md's "Order & Payment
 * State Models" §3 and the approved Block 4C decisions:
 *
 *   pending    -> cancelled
 *   paid       -> processing
 *   processing -> shipped
 *   shipped    -> completed
 *
 * Named "Merchant..." specifically so a future system/webhook transition
 * table (pending -> paid, {paid,...} -> refunded) is never tempted to share
 * this one — the two are structurally different concerns with different
 * actors, and must never be merged.
 */
final class MerchantOrderStatusTransitions
{
    /**
     * @var array<string, list<OrderStatus>>
     */
    private const ALLOWED = [
        OrderStatus::Pending->value => [OrderStatus::Cancelled],
        OrderStatus::Paid->value => [OrderStatus::Processing],
        OrderStatus::Processing->value => [OrderStatus::Shipped],
        OrderStatus::Shipped->value => [OrderStatus::Completed],
    ];

    public static function isAllowed(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value] ?? [], true);
    }
}
