<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by InventoryAdjustmentService when a requested delta would take
 * quantity_on_hand below zero. Raised from inside the locked transaction,
 * after acquiring the row lock — never from a pre-check against a possibly
 * stale read — so it reflects the true quantity at the moment of mutation.
 */
class InsufficientInventoryException extends RuntimeException
{
    public function __construct(int $available, int $requestedDelta)
    {
        parent::__construct(
            "Insufficient inventory: {$available} on hand, requested change of {$requestedDelta} would result in a negative quantity."
        );
    }
}
