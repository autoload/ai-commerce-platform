<?php

namespace App\Exceptions;

use App\Enums\OrganizationStatus;
use RuntimeException;

/**
 * Thrown by OrganizationLifecycleService when the requested action is not
 * valid from the organization's current (locked, freshly re-read) status —
 * see OrganizationLifecycleTransitions. Raised from inside the locked
 * transaction, after acquiring the row lock, so it reflects the true
 * status at the moment of mutation, not a possibly-stale read from before
 * the request was authorized.
 */
class InvalidOrganizationTransitionException extends RuntimeException
{
    public function __construct(OrganizationStatus $from, OrganizationStatus $to)
    {
        parent::__construct(
            "Cannot transition organization from '{$from->value}' to '{$to->value}'."
        );
    }
}
