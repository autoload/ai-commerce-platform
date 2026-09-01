<?php

namespace Tests\Doubles;

use App\Services\StripePaymentIntentGateway;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * Test double for StripePaymentIntentGateway — records every create() call
 * (for asserting the amount/currency/metadata/idempotency key a test
 * expects) and lets a test script either a canned PaymentIntent response
 * or a specific Stripe SDK exception, without any real network call or
 * Stripe credentials. Bound into the container in place of
 * StripeApiPaymentIntentGateway via $this->app->instance(...).
 */
class FakePaymentIntentGateway implements StripePaymentIntentGateway
{
    /** @var array<int, array{params: array<string, mixed>, idempotency_key: string}> */
    public array $calls = [];

    private ?PaymentIntent $nextResult = null;

    private ?ApiErrorException $nextException = null;

    public function willReturn(PaymentIntent $paymentIntent): void
    {
        $this->nextResult = $paymentIntent;
        $this->nextException = null;
    }

    public function willThrow(ApiErrorException $exception): void
    {
        $this->nextException = $exception;
        $this->nextResult = null;
    }

    public function create(array $params, string $idempotencyKey): PaymentIntent
    {
        $this->calls[] = ['params' => $params, 'idempotency_key' => $idempotencyKey];

        if ($this->nextException) {
            throw $this->nextException;
        }

        if ($this->nextResult) {
            return $this->nextResult;
        }

        $id = 'pi_fake_'.uniqid();

        return PaymentIntent::constructFrom([
            'id' => $id,
            'client_secret' => $id.'_secret_fake',
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'status' => 'requires_payment_method',
        ]);
    }
}
