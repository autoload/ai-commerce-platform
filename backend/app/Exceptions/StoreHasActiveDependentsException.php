<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by StoreController::destroy() when the store still has non-archived
 * Products or non-terminal Orders under it. Implements database-design.md's
 * "Cascading soft-deletes — resolved" decision: deletion is blocked at the
 * application layer rather than cascading, so an admin must explicitly
 * archive/resolve dependents first. Deliberately does not consider
 * Categories or Customers — neither has a merchant-facing archive/
 * reassignment workflow yet, so there would be no way to ever satisfy such
 * a block.
 */
class StoreHasActiveDependentsException extends RuntimeException
{
    public static function forActiveProducts(): self
    {
        return new self('Store has one or more non-archived products. Archive them before deleting this store.');
    }

    public static function forNonTerminalOrders(): self
    {
        return new self('Store has one or more orders that are not yet completed, cancelled, or refunded. Resolve them before deleting this store.');
    }
}
