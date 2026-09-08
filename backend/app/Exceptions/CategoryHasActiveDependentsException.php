<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by CategoryController::destroy() when the category still has one
 * or more non-soft-deleted Products referencing it. Mirrors
 * StoreHasActiveDependentsException's shape/reasoning: deletion is blocked
 * at the application layer rather than cascading or reassigning — an admin
 * must explicitly reassign or remove the referencing products first.
 * products.category_id's nullOnDelete() FK action does NOT cover this case
 * (it only fires on a hard DB DELETE, never on Eloquent's SoftDeletes
 * UPDATE), which is exactly why this application-layer guard exists.
 */
class CategoryHasActiveDependentsException extends RuntimeException
{
    public static function forActiveProducts(): self
    {
        return new self('Category has one or more products still assigned to it. Reassign or remove them before deleting this category.');
    }
}
