<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\UnknownPaymentIntentException;
use App\Http\Controllers\Controller;
use App\Services\StripePaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * database-design.md §4, STEP 3C: receives Stripe's asynchronous
 * PaymentIntent events. Unauthenticated and not tenant-scoped — there is
 * no Sanctum identity or tenant context for an external system callback.
 * Stripe signature verification (Stripe\Webhook::constructEvent()) is the
 * entire trust boundary; once verified, all business logic is delegated
 * to StripePaymentWebhookService, which treats the local Payment row —
 * never the event's own metadata — as authoritative.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripePaymentWebhookService $webhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature') ?? '',
                config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        try {
            $this->webhookService->process($event);
        } catch (UnknownPaymentIntentException) {
            return response()->json(['message' => 'Unknown PaymentIntent.'], 404);
        }

        return response()->json(['message' => 'ok']);
    }
}
