<?php

namespace App\Services;

use Stripe\Refund;

/**
 * Thin seam around the installed stripe/stripe-php SDK's Refund calls —
 * mirrors StripePaymentIntentGateway's exact rationale: stripe-php uses
 * its own cURL client, not Laravel's Http facade, so Http::fake() cannot
 * intercept it. Binding this interface lets tests swap in a fake
 * implementation via the container instead. Exposes only the operation
 * this codebase actually needs; exceptions from the underlying SDK
 * (Stripe\Exception\*) propagate to the caller unchanged — this gateway
 * does not wrap or reinterpret them.
 */
interface StripeRefundGateway
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function create(array $params, string $idempotencyKey): Refund;
}
