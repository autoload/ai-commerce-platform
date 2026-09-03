<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * Thrown by PaymentRetryService when the order already has an active
 * (RequiresPayment/Processing) Payment carrying a *different* idempotency
 * key than the current retry request — a genuine conflict, not a replay of
 * the same request. Per the approved STEP 3D design, this is a narrower
 * behavior than the original pre-STEP-3A-3C draft: no auto-cancel-and-
 * replace of the prior attempt, since that would require a new Stripe
 * gateway cancel() method this step deliberately does not add.
 */
class ActivePaymentExistsException extends RuntimeException
{
    public function __construct(Order $order)
    {
        parent::__construct("Order {$order->id} already has an active payment attempt in progress.");
    }
}
