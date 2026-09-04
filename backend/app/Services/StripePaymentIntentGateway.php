<?php

namespace App\Services;

use Stripe\PaymentIntent;

/**
 * Thin seam around the installed stripe/stripe-php SDK's PaymentIntent
 * calls — exists purely for testability: stripe-php uses its own cURL
 * client, not Laravel's Http facade, so Http::fake() cannot intercept it.
 * Binding this interface lets tests swap in a fake implementation via the
 * container instead. Exposes only the operations this codebase actually
 * needs; exceptions from the underlying SDK (Stripe\Exception\*) propagate
 * to the caller unchanged — this gateway does not wrap or reinterpret them.
 *
 * retrieve()/cancel() (Phase 6, database-design.md §12) support the expiry
 * sweep's live pre-cancellation guard and best-effort PaymentIntent
 * cancellation — added alongside create() (STEP 3B), not a replacement.
 */
interface StripePaymentIntentGateway
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function create(array $params, string $idempotencyKey): PaymentIntent;

    public function retrieve(string $stripePaymentIntentId): PaymentIntent;

    public function cancel(string $stripePaymentIntentId): PaymentIntent;
}
