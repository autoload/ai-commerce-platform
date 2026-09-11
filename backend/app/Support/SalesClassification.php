<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pure, dependency-free helper — same discipline as
 * MerchantOrderStatusTransitions: knows nothing about RBAC, HTTP, or any
 * specific consumer. The single authoritative definition of "which orders
 * constitute a completed sale," reused identically by CustomerController
 * (per-customer Net Sales / total_spent) and, later, AnalyticsService — so
 * the two can never disagree about what counts as a sale.
 *
 * Deliberately NOT the same set as RefundService::REFUNDABLE_ORDER_STATUSES
 * (which answers a different question — "still eligible for a NEW refund,"
 * correctly excluding an already-Refunded order). This set answers "did
 * this order ever constitute a sale" and correctly INCLUDES Refunded — a
 * refunded order was still a genuine sale before being refunded; the
 * refund itself is netted out separately (see CustomerController's
 * sales_refunds subquery), never by excluding the order from Gross Sales.
 */
final class SalesClassification
{
    /** @var list<OrderStatus> */
    public const GROSS_SALE_STATUSES = [
        OrderStatus::Paid,
        OrderStatus::Processing,
        OrderStatus::Shipped,
        OrderStatus::Completed,
        OrderStatus::Refunded,
    ];

    /**
     * @param  Builder<Order>  $ordersQuery
     * @return Builder<Order>
     */
    public static function scopeGrossSaleOrders(Builder $ordersQuery): Builder
    {
        return $ordersQuery->whereIn('status', self::GROSS_SALE_STATUSES);
    }
}
