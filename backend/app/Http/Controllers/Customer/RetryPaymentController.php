<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ActivePaymentExistsException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\OrderNotEligibleForRetryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\RetryPaymentRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentRetryService;
use App\Support\CustomerContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\ErrorObject;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\IdempotencyException;
use Stripe\PaymentIntent;

/**
 * Phase 3 STEP 3D — POST /api/orders/{order}/payment-retry. Mirrors
 * CheckoutController's discipline exactly: {order} is resolved and scoped
 * server-side (never implicit route-model binding), Customer/Order/Store
 * identity comes only from CustomerContext, and every failure mode is
 * mapped to an HTTP status explicitly here — this codebase has no global
 * exception-to-status mapping. PaymentRetryService owns the entire
 * transactional/concurrency-sensitive sequence; this controller only
 * translates its outcomes.
 */
class RetryPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRetryService $paymentRetryService,
    ) {}

    public function store(RetryPaymentRequest $request): JsonResponse
    {
        $context = app(CustomerContext::class);
        $order = $this->resolveOrder($request, $context);
        $idempotencyKey = $request->validated()['idempotency_key'];

        try {
            $result = $this->paymentRetryService->retry($order, $idempotencyKey);
        } catch (OrderNotEligibleForRetryException $e) {
            abort(422, $e->getMessage());
        } catch (ActivePaymentExistsException $e) {
            abort(409, $e->getMessage());
        } catch (InsufficientInventoryException $e) {
            abort(422, $e->getMessage());
        } catch (IdempotencyException $e) {
            if ($e->getStripeCode() === ErrorObject::CODE_IDEMPOTENCY_KEY_IN_USE) {
                abort(409, 'This retry request is already being processed. Please try again shortly.');
            }

            abort(409, 'This idempotency key was already used for a different retry request.');
        } catch (ApiErrorException $e) {
            report($e);
            abort(502, 'Unable to reach the payment provider. Please try again.');
        } catch (QueryException $e) {
            if ($this->isLockWaitTimeout($e)) {
                abort(503, 'This order is currently being processed. Please try again shortly.');
            }

            throw $e;
        }

        return $this->respond($result);
    }

    /**
     * {order} is deliberately NOT implicitly route-bound — scoped directly
     * to the authenticated customer's own id, the same discipline
     * Merchant\OrderController::resolveOrder() uses for {store}. A client
     * cannot reach another customer's order by id, regardless of store.
     */
    private function resolveOrder(Request $request, CustomerContext $context): Order
    {
        $order = Order::where('id', $request->route('order'))
            ->where('customer_id', $context->customer->id)
            ->first();

        if (! $order) {
            abort(404);
        }

        return $order;
    }

    /**
     * Narrow detection of MySQL error 1205 ("Lock wait timeout exceeded")
     * only — a genuinely blocked second request against an already-locked
     * Order row. Every other QueryException propagates unmapped, per the
     * approved design (no generic QueryException-to-503 mapping).
     */
    private function isLockWaitTimeout(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1205;
    }

    /**
     * @param  array{order: Order, payment: Payment, payment_intent: PaymentIntent, is_new: bool}  $result
     */
    private function respond(array $result): JsonResponse
    {
        $paymentIntent = $result['payment_intent'];

        $this->logAmountMismatchIfAny($result['order'], $paymentIntent);

        return response()->json([
            'data' => new OrderResource($result['order']),
            'payment' => [
                'client_secret' => $paymentIntent->client_secret,
                'stripe_payment_intent_id' => $paymentIntent->id,
            ],
        ], $result['is_new'] ? 201 : 200);
    }

    /**
     * Same accepted, bounded residual-risk logging CheckoutController
     * already performs — informational only, never a Stripe mutation.
     */
    private function logAmountMismatchIfAny(Order $order, PaymentIntent $paymentIntent): void
    {
        $orderTotalCents = (int) round(((float) $order->total) * 100);

        if ($orderTotalCents !== $paymentIntent->amount) {
            Log::warning('Payment retry: Stripe amount diverged from the order total.', [
                'order_id' => $order->id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_amount_cents' => $paymentIntent->amount,
                'order_total_cents' => $orderTotalCents,
            ]);
        }
    }
}
