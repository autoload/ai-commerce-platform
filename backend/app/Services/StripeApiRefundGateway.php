<?php

namespace App\Services;

use Stripe\Refund;
use Stripe\StripeClient;

/**
 * The real implementation of StripeRefundGateway, wrapping
 * Stripe\StripeClient (the same singleton StripeApiPaymentIntentGateway
 * uses, bound in AppServiceProvider using config('services.stripe.secret')).
 * Bound to the interface only in the application container — tests bind a
 * fake instead.
 */
class StripeApiRefundGateway implements StripeRefundGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function create(array $params, string $idempotencyKey): Refund
    {
        return $this->stripe->refunds->create($params, [
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
