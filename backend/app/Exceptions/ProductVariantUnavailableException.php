<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use RuntimeException;

/**
 * Thrown by CheckoutOrderCreationService when a line item's variant fails
 * checkout-time revalidation — wrong store, or not Active. Per CLAUDE.md,
 * cart contents are untrusted input; availability is never accepted from
 * the client and must be re-checked against MySQL at the moment of order
 * creation, not assumed from whatever earlier cart-read/validation step
 * produced the line item.
 */
class ProductVariantUnavailableException extends RuntimeException
{
    public function __construct(ProductVariant $variant, string $reason)
    {
        parent::__construct("Product variant {$variant->id} is unavailable for checkout: {$reason}.");
    }
}
