<?php

namespace App\Http\Controllers\Merchant;

use App\Exceptions\ActiveRefundExistsException;
use App\Exceptions\RefundNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\RefundCreateRequest;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Refund;
use App\Services\RefundService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Stripe\ErrorObject;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;

/**
 * Phase 9D — POST /api/stores/{store}/orders/{order}/refund. Mirrors
 * RetryPaymentController's discipline exactly: {order} is resolved and
 * scoped server-side (never implicit route-model binding), and every
 * failure mode is mapped to an HTTP status explicitly here — this
 * codebase has no global exception-to-status mapping. RefundService owns
 * the entire transactional/concurrency-sensitive sequence; this
 * controller only translates its outcomes.
 */
class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
    ) {}

    public function store(RefundCreateRequest $request): JsonResponse
    {
        $context = app(TenantContext::class);
        $order = $this->resolveOrder($request, $context);

        Gate::authorize('create', [Refund::class, $order]);

        $data = $request->validated();

        try {
            $result = $this->refundService->refund(
                $order,
                $data['reason'] ?? null,
                $data['idempotency_key'],
                $context->user,
            );
        } catch (RefundNotEligibleException $e) {
            abort(422, $e->getMessage());
        } catch (ActiveRefundExistsException $e) {
            abort(409, $e->getMessage());
        } catch (InvalidRequestException $e) {
            // Stripe's own "can't refund an already-refunded charge/
            // PaymentIntent" rejection (verified against the official API
            // docs during design) — a clean, expected application-level
            // error, never a generic 502.
            abort(422, 'This payment has already been refunded or cannot be refunded.');
        } catch (IdempotencyException $e) {
            if ($e->getStripeCode() === ErrorObject::CODE_IDEMPOTENCY_KEY_IN_USE) {
                abort(409, 'This refund request is already being processed. Please try again shortly.');
            }

            abort(409, 'This idempotency key was already used for a different refund request.');
        } catch (ApiErrorException $e) {
            report($e);
            abort(502, 'Unable to reach the payment provider. Please try again.');
        } catch (QueryException $e) {
            if ($this->isLockWaitTimeout($e)) {
                abort(503, 'This order is currently being processed. Please try again shortly.');
            }

            throw $e;
        }

        return (new RefundResource($result['refund']))
            ->response()
            ->setStatusCode($result['is_new'] ? 201 : 200);
    }

    /**
     * {order} is deliberately NOT implicitly route-bound — resolved and
     * store-scoped at the controller level, the same discipline
     * OrderController::resolveOrder() uses.
     */
    private function resolveOrder(Request $request, TenantContext $context): Order
    {
        $order = Order::where('id', $request->route('order'))
            ->where('store_id', $context->store->id)
            ->first();

        if (! $order) {
            abort(404);
        }

        return $order;
    }

    /**
     * Narrow detection of MySQL error 1205 ("Lock wait timeout exceeded")
     * only — matches RetryPaymentController's identical detection.
     */
    private function isLockWaitTimeout(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1205;
    }
}
