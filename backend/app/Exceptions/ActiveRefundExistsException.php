<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * Thrown by RefundService when the target payment already has a
 * pending/succeeded Refund. Per the approved Phase 9D design, this is a
 * blanket rejection regardless of whether the current request is a
 * same-key replay — refunds.idempotency_key is deliberately not added, so
 * there is no local way to distinguish a replay from a genuinely
 * different request once a prior refund attempt has already committed;
 * the orders row lock (held across the entire sequence, including the
 * Stripe call) is what prevents a second Stripe API call from ever being
 * reached while one is already active.
 */
class ActiveRefundExistsException extends RuntimeException
{
    public function __construct(Order $order)
    {
        parent::__construct("Order {$order->id} already has an active refund in progress.");
    }
}
