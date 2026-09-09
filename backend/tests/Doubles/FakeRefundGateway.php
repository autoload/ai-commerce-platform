<?php

namespace Tests\Doubles;

use App\Services\StripeRefundGateway;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;

/**
 * Test double for StripeRefundGateway — mirrors FakePaymentIntentGateway's
 * exact shape: records every create() call (for asserting the
 * payment_intent/amount/idempotency key a test expects) and lets a test
 * script either a canned Refund response or a specific Stripe SDK
 * exception, without any real network call or Stripe credentials. Bound
 * into the container in place of StripeApiRefundGateway via
 * $this->app->instance(...).
 */
class FakeRefundGateway implements StripeRefundGateway
{
    /** @var array<int, array{params: array<string, mixed>, idempotency_key: string}> */
    public array $calls = [];

    private ?Refund $nextResult = null;

    private ?ApiErrorException $nextException = null;

    public function willReturn(Refund $refund): void
    {
        $this->nextResult = $refund;
        $this->nextException = null;
    }

    public function willThrow(ApiErrorException $exception): void
    {
        $this->nextException = $exception;
        $this->nextResult = null;
    }

    public function create(array $params, string $idempotencyKey): Refund
    {
        $this->calls[] = ['params' => $params, 'idempotency_key' => $idempotencyKey];

        if ($this->nextException) {
            throw $this->nextException;
        }

        if ($this->nextResult) {
            return $this->nextResult;
        }

        $id = 're_fake_'.uniqid();

        return Refund::constructFrom([
            'id' => $id,
            'payment_intent' => $params['payment_intent'],
            'amount' => $params['amount'],
            'status' => 'succeeded',
        ]);
    }
}
