<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by StripePaymentWebhookService when a supported payment_intent.*
 * event references a Stripe PaymentIntent id with no matching local
 * Payment row (payments.stripe_payment_intent_id, unique). Thrown from
 * inside the webhook's DB::transaction() closure so the entire
 * transaction — including the stripe_webhook_events insert already made
 * earlier in the same transaction — rolls back, letting a genuine Stripe
 * retry find no trace of this event and reprocess it cleanly (e.g. if
 * this arrived before our own checkout transaction had committed).
 * StripeWebhookController maps this to 404, a status Stripe will retry.
 */
class UnknownPaymentIntentException extends RuntimeException
{
    public function __construct(string $stripePaymentIntentId)
    {
        parent::__construct("No local Payment found for Stripe PaymentIntent '{$stripePaymentIntentId}'.");
    }
}
