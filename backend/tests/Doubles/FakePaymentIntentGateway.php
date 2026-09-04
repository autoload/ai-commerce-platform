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
 *
 * retrieve()/cancel() (Phase 6) are scripted independently of create(),
 * each via its own willReturn.../willThrow... pair, since the expiry
 * sweep calls both against a PaymentIntent this fake never created itself.
 */
class FakePaymentIntentGateway implements StripePaymentIntentGateway
{
    /** @var array<int, array{params: array<string, mixed>, idempotency_key: string}> */
    public array $calls = [];

    /** @var array<int, string> stripe_payment_intent_id values passed to retrieve() */
    public array $retrieveCalls = [];

    /** @var array<int, string> stripe_payment_intent_id values passed to cancel() */
    public array $cancelCalls = [];

    private ?PaymentIntent $nextResult = null;

    private ?ApiErrorException $nextException = null;

    private ?PaymentIntent $nextRetrieveResult = null;

    private ?ApiErrorException $nextRetrieveException = null;

    private ?PaymentIntent $nextCancelResult = null;

    private ?ApiErrorException $nextCancelException = null;

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

    public function willReturnOnRetrieve(PaymentIntent $paymentIntent): void
    {
        $this->nextRetrieveResult = $paymentIntent;
        $this->nextRetrieveException = null;
    }

    public function willThrowOnRetrieve(ApiErrorException $exception): void
    {
        $this->nextRetrieveException = $exception;
        $this->nextRetrieveResult = null;
    }

    public function willReturnOnCancel(PaymentIntent $paymentIntent): void
    {
        $this->nextCancelResult = $paymentIntent;
        $this->nextCancelException = null;
    }

    public function willThrowOnCancel(ApiErrorException $exception): void
    {
        $this->nextCancelException = $exception;
        $this->nextCancelResult = null;
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

    public function retrieve(string $stripePaymentIntentId): PaymentIntent
    {
        $this->retrieveCalls[] = $stripePaymentIntentId;

        if ($this->nextRetrieveException) {
            throw $this->nextRetrieveException;
        }

        if ($this->nextRetrieveResult) {
            return $this->nextRetrieveResult;
        }

        return PaymentIntent::constructFrom([
            'id' => $stripePaymentIntentId,
            'status' => 'requires_payment_method',
        ]);
    }

    public function cancel(string $stripePaymentIntentId): PaymentIntent
    {
        $this->cancelCalls[] = $stripePaymentIntentId;

        if ($this->nextCancelException) {
            throw $this->nextCancelException;
        }

        if ($this->nextCancelResult) {
            return $this->nextCancelResult;
        }

        return PaymentIntent::constructFrom([
            'id' => $stripePaymentIntentId,
            'status' => 'canceled',
        ]);
    }
}
