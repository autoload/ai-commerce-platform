<?php

namespace App\Exceptions;

use App\Models\Customer;
use App\Models\Store;
use RuntimeException;

/**
 * Thrown by CheckoutOrderCreationService when the supplied Customer does
 * not belong to the supplied Store (and, transitively, its Organization).
 * This is a hard service-level invariant, not something delegated to a
 * caller/controller to guarantee — CLAUDE.md's tenant-isolation rule
 * applies under every code path, so this service must reject a mismatched
 * Customer/Store pair itself rather than trust whoever invoked it.
 */
class CustomerStoreMismatchException extends RuntimeException
{
    public function __construct(Customer $customer, Store $store)
    {
        parent::__construct(
            "Customer {$customer->id} does not belong to store {$store->id}."
        );
    }
}
