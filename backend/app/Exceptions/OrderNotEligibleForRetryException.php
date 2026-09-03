<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * Thrown by PaymentRetryService when the order itself is not eligible for a
 * payment retry — either its status is not Pending, or it has no prior
 * terminal (Failed/Canceled) Payment to retry in the first place. Raised
 * from inside the locked transaction, after acquiring the orders row lock,
 * so it reflects the true status at the moment of the retry attempt.
 */
class OrderNotEligibleForRetryException extends RuntimeException
{
    public function __construct(Order $order, string $reason)
    {
        parent::__construct("Order {$order->id} is not eligible for a payment retry: {$reason}.");
    }
}
