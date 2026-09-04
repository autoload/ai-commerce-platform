<?php

namespace App\Services;

use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * The real implementation of StripePaymentIntentGateway, wrapping
 * Stripe\StripeClient (bound in AppServiceProvider using
 * config('services.stripe.secret')). Bound to the interface only in the
 * application container — tests bind a fake instead.
 */
class StripeApiPaymentIntentGateway implements StripePaymentIntentGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function create(array $params, string $idempotencyKey): PaymentIntent
    {
        return $this->stripe->paymentIntents->create($params, [
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function retrieve(string $stripePaymentIntentId): PaymentIntent
    {
        return $this->stripe->paymentIntents->retrieve($stripePaymentIntentId);
    }

    public function cancel(string $stripePaymentIntentId): PaymentIntent
    {
        return $this->stripe->paymentIntents->cancel($stripePaymentIntentId);
    }
}
