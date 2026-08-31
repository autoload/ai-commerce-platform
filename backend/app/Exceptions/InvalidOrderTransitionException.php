<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use RuntimeException;

/**
 * Thrown by OrderStatusUpdateService when the requested target status is
 * not a merchant-allowed transition from the order's current (locked,
 * freshly re-read) status — see MerchantOrderStatusTransitions. Raised
 * from inside the locked transaction, after acquiring the row lock, so it
 * reflects the true status at the moment of mutation, not a possibly-stale
 * read from before the request was authorized.
 */
class InvalidOrderTransitionException extends RuntimeException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            "Cannot transition order from '{$from->value}' to '{$to->value}'."
        );
    }
}
