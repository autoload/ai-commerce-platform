<?php

namespace App\Enums;

/**
 * Database Design 2.6 retires `Sale` in favor of `Checkout`/`Release` —
 * see "Order & Payment State Models" §9. `Checkout` claims inventory at
 * checkout time (a claim, not revenue); `Release` reverses a pre-payment
 * claim (payment failure, cancellation, or expiry); `Refund` reverses a
 * post-payment claim, only ever reachable from a paid order. Verified
 * unused in every shipped code path before this change — no data
 * migration/backfill was needed.
 */
enum InventoryTransactionReason: string
{
    case Checkout = 'checkout';
    case Release = 'release';
    case Refund = 'refund';
    case Restock = 'restock';
    case Adjustment = 'adjustment';
}
