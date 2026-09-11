<?php

namespace App\Support;

use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Builder;

/**
 * Small, single-purpose refund-classification helper — kept structurally
 * separate from SalesClassification per the Analytics v1 Design Review
 * (revision #9): SalesClassification stays narrowly scoped to "what counts
 * as a Gross Sale"; this class owns the one adjacent-but-distinct
 * question, "which succeeded refunds count as a Sales Refund" (a refund
 * against an order that was itself a genuine Gross Sale). It composes
 * SalesClassification::GROSS_SALE_STATUSES by reference rather than
 * duplicating the status list, but is never merged into that class.
 *
 * Reused identically by CustomerController (all-time, per-customer Net
 * Sales) and AnalyticsService (date-bounded, store-wide Net Sales) — the
 * two can never disagree about what a "Sales Refund" is.
 *
 * Deliberately excludes G3-B/9E-4 compensation refunds without ever
 * inspecting status_reason: those refunds' orders stay Cancelled, which is
 * never in GROSS_SALE_STATUSES, so the whereIn below excludes them
 * structurally.
 */
final class SalesRefundClassification
{
    /**
     * Applies the "this is a Sales Refund" predicate to a Refund query
     * builder that has already been joined to `orders` (the join is the
     * caller's responsibility — this method only adds WHERE clauses,
     * matching SalesClassification::scopeGrossSaleOrders()'s "takes a
     * builder in, applies filters, returns it" shape).
     *
     * @param  Builder<Refund>  $refundQuery
     * @return Builder<Refund>
     */
    public static function scopeSuccessfulSalesRefunds(Builder $refundQuery): Builder
    {
        return $refundQuery
            ->where('refunds.status', RefundStatus::Succeeded)
            ->whereIn('orders.status', SalesClassification::GROSS_SALE_STATUSES);
    }
}
