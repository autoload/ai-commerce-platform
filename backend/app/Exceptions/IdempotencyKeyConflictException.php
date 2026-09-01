<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by CheckoutOrderCreationService when a customer resubmits an
 * idempotency key that already belongs to an existing Order, but with a
 * different payload hash — database-design.md §11: "same key, different
 * payload -> reject clearly (this must be actively detected, never
 * silently resolved either way)". A matching hash instead recovers and
 * returns the existing Order silently — this exception is only for the
 * mismatched case.
 */
class IdempotencyKeyConflictException extends RuntimeException
{
    public function __construct(string $idempotencyKey)
    {
        parent::__construct(
            "Idempotency key '{$idempotencyKey}' was already used for a different checkout request."
        );
    }
}
