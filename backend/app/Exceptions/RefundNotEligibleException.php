<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * Thrown by RefundService when the order/payment state isn't eligible for
 * a refund — order status not in {paid,processing,shipped,completed}, or
 * no succeeded Payment exists for it. Raised from inside the locked
 * transaction, after acquiring the orders row lock, so it reflects the
 * true status at the moment of the refund attempt.
 */
class RefundNotEligibleException extends RuntimeException
{
    public function __construct(Order $order, string $reason)
    {
        parent::__construct("Order {$order->id} is not eligible for a refund: {$reason}.");
    }
}
