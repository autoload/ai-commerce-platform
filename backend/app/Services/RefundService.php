<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\ActiveRefundExistsException;
use App\Exceptions\RefundNotEligibleException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9D — the single mutation path for a merchant-initiated refund.
 * Mirrors PaymentRetryService's proven shape exactly: the `orders` row
 * lock is held across the entire sequence, including the Stripe API call
 * — the same deliberate, approved exception to this project's general
 * "never hold a lock across a network call" rule, for the same reason:
 * it's what makes "at most one active refund per payment" an actual
 * invariant rather than best-effort, and what prevents a second,
 * concurrent request from ever reaching Stripe while one is already
 * active.
 *
 * Full refunds only (approved scope) — amount is always derived from
 * payment.amount, never client-supplied. The local Refund row is always
 * inserted `pending`, regardless of what Stripe's synchronous response
 * reports; StripePaymentWebhookService's refund.created/refund.updated
 * handling is the sole authority for the succeeded/failed transition.
 */
class RefundService
{
    private const ACTIVE_REFUND_STATUSES = [
        RefundStatus::Pending,
        RefundStatus::Succeeded,
    ];

    private const REFUNDABLE_ORDER_STATUSES = [
        OrderStatus::Paid,
        OrderStatus::Processing,
        OrderStatus::Shipped,
        OrderStatus::Completed,
    ];

    /**
     * Phase 9E-2 (G3-B) — the exact G3-A alarm value (StripePaymentWebhookService's
     * PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON). Duplicated here as a literal,
     * not a cross-class constant reference, since the two classes have no
     * other coupling and this project has no shared-constants convention —
     * the literal is exercised end-to-end by tests on both sides.
     */
    private const G3B_CLOSURE_ALARM_REASON = 'payment_succeeded_after_closure';

    /**
     * The 9E-3 alarm value (StripePaymentWebhookService's
     * PAYMENT_SUCCEEDED_AFTER_EXPIRY_CANCELLATION_REASON). Duplicated as a
     * literal for the same reason G3B_CLOSURE_ALARM_REASON above is —
     * deliberately never reused as G3B_CLOSURE_ALARM_REASON's synonym, since
     * that value's established meaning implies a locally Succeeded Payment,
     * which is never true here (see refundLateSucceededExpiredPayment()).
     */
    private const EXPIRY_SWEEP_ALARM_REASON = 'payment_succeeded_after_expiry_cancellation';

    public function __construct(
        private readonly StripeRefundGateway $refundGateway,
    ) {}

    /**
     * @return array{refund: Refund, is_new: bool}
     *
     * @throws RefundNotEligibleException if the order/payment state isn't refundable
     * @throws ActiveRefundExistsException if a refund is already pending/succeeded for this payment
     */
    public function refund(Order $order, ?string $reason, string $idempotencyKey, ?User $initiatedBy): array
    {
        return DB::transaction(function () use ($order, $reason, $idempotencyKey, $initiatedBy) {
            /** @var Order $locked */
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $this->isRefundableOrderState($locked)) {
                throw new RefundNotEligibleException($locked, 'order status is not refundable');
            }

            /** @var Payment|null $payment */
            $payment = Payment::where('order_id', $locked->id)
                ->where('status', PaymentStatus::Succeeded)
                ->first();

            if (! $payment) {
                throw new RefundNotEligibleException($locked, 'no succeeded payment exists for this order');
            }

            $hasActiveRefund = Refund::where('payment_id', $payment->id)
                ->whereIn('status', self::ACTIVE_REFUND_STATUSES)
                ->exists();

            if ($hasActiveRefund) {
                throw new ActiveRefundExistsException($locked);
            }

            $stripeIdempotencyKey = hash('sha256', "refund:{$payment->id}:{$idempotencyKey}");

            $stripeRefund = $this->refundGateway->create([
                'payment_intent' => $payment->stripe_payment_intent_id,
                'amount' => (int) round(((float) $payment->amount) * 100),
            ], $stripeIdempotencyKey);

            try {
                $refund = DB::transaction(function () use ($locked, $payment, $stripeRefund, $reason, $initiatedBy) {
                    $refund = new Refund;
                    $refund->organization_id = $locked->organization_id;
                    $refund->store_id = $locked->store_id;
                    $refund->order_id = $locked->id;
                    $refund->payment_id = $payment->id;
                    $refund->initiated_by_user_id = $initiatedBy?->id;
                    $refund->stripe_refund_id = $stripeRefund->id;
                    $refund->amount = $payment->amount;
                    $refund->reason = $reason;
                    $refund->status = RefundStatus::Pending;
                    $refund->save();

                    return $refund;
                });

                return ['refund' => $refund, 'is_new' => true];
            } catch (QueryException $e) {
                if (! $this->isDuplicateEntryViolation($e)) {
                    throw $e;
                }

                // Defense-in-depth only — the orders row lock held across
                // the whole sequence above already prevents a second
                // request from ever reaching this point while a refund is
                // active, so this path is not expected to be exercised in
                // normal operation. Resolved by looking up the row itself,
                // the same backstop PaymentRetryService uses for its own
                // analogous stripe_payment_intent_id collision.
                $existing = Refund::where('payment_id', $payment->id)
                    ->where('stripe_refund_id', $stripeRefund->id)
                    ->first();

                if ($existing) {
                    return ['refund' => $existing, 'is_new' => false];
                }

                throw new ActiveRefundExistsException($locked);
            }
        });
    }

    /**
     * A separate, structurally isolated entry point for the 9E-3
     * expiry-sweep late-success compensation case — deliberately NOT a
     * branch inside refund() above. refund()'s Payment lookup requires
     * PaymentStatus::Succeeded, which this case's Payment can never satisfy
     * (Payment.status stays Canceled by design — see
     * StripePaymentWebhookService::recordLatePaymentSucceededAfterExpirySweep()
     * and database-design.md §14). Reusing refund()'s branch would either
     * dead-end at that lookup or require conditionally changing it, which
     * would entangle two incompatible Payment-state assumptions in one
     * method. This method reuses the same Stripe-call/Refund-row/locking
     * shape as refund() but with its own, independent eligibility check —
     * refund()'s REFUNDABLE_ORDER_STATUSES/isRefundableOrderState() and the
     * existing G3-B carve-out are completely untouched by this method.
     *
     * @return array{refund: Refund, is_new: bool}
     *
     * @throws RefundNotEligibleException if the order/payment state isn't eligible for this compensation path
     * @throws ActiveRefundExistsException if a refund is already pending/succeeded for this payment
     */
    public function refundLateSucceededExpiredPayment(Order $order, ?string $reason, string $idempotencyKey, ?User $initiatedBy): array
    {
        return DB::transaction(function () use ($order, $reason, $idempotencyKey, $initiatedBy) {
            /** @var Order $locked */
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $this->isExpirySweepLateSuccessState($locked)) {
                throw new RefundNotEligibleException($locked, 'order is not in the expiry-sweep late-success compensation state');
            }

            /** @var Payment|null $payment */
            $payment = Payment::where('order_id', $locked->id)
                ->where('status', PaymentStatus::Canceled)
                ->where('failure_reason', 'expired')
                ->first();

            if (! $payment || ! $payment->stripe_payment_intent_id) {
                throw new RefundNotEligibleException($locked, 'no expiry-sweep-canceled payment with a Stripe PaymentIntent exists for this order');
            }

            $hasActiveRefund = Refund::where('payment_id', $payment->id)
                ->whereIn('status', self::ACTIVE_REFUND_STATUSES)
                ->exists();

            if ($hasActiveRefund) {
                throw new ActiveRefundExistsException($locked);
            }

            $stripeIdempotencyKey = hash('sha256', "refund:{$payment->id}:{$idempotencyKey}");

            // Not created until Stripe actually confirms the refund
            // request — the local Refund row is never inserted
            // optimistically (same discipline as refund() above), so a
            // failed/timed-out call leaves no local state change beyond
            // what already existed.
            $stripeRefund = $this->refundGateway->create([
                'payment_intent' => $payment->stripe_payment_intent_id,
                'amount' => (int) round(((float) $payment->amount) * 100),
            ], $stripeIdempotencyKey);

            try {
                $refund = DB::transaction(function () use ($locked, $payment, $stripeRefund, $reason, $initiatedBy) {
                    $refund = new Refund;
                    $refund->organization_id = $locked->organization_id;
                    $refund->store_id = $locked->store_id;
                    $refund->order_id = $locked->id;
                    $refund->payment_id = $payment->id;
                    $refund->initiated_by_user_id = $initiatedBy?->id;
                    $refund->stripe_refund_id = $stripeRefund->id;
                    $refund->amount = $payment->amount;
                    $refund->reason = $reason;
                    $refund->status = RefundStatus::Pending;
                    $refund->save();

                    return $refund;
                });

                return ['refund' => $refund, 'is_new' => true];
            } catch (QueryException $e) {
                if (! $this->isDuplicateEntryViolation($e)) {
                    throw $e;
                }

                // Same defense-in-depth backstop as refund() above.
                $existing = Refund::where('payment_id', $payment->id)
                    ->where('stripe_refund_id', $stripeRefund->id)
                    ->first();

                if ($existing) {
                    return ['refund' => $existing, 'is_new' => false];
                }

                throw new ActiveRefundExistsException($locked);
            }
        });
    }

    /**
     * Narrow detection of MySQL error 1062 ("Duplicate entry") only —
     * mirrors PaymentRetryService's identical, equally narrow detection.
     */
    private function isDuplicateEntryViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /**
     * Phase 9E-2 (G3-B) — exactly two paths, never widened into a general
     * "Cancelled orders are refundable" rule: the normal Phase 9D
     * REFUNDABLE_ORDER_STATUSES set, unchanged, OR the single narrow G3-A
     * compensation case (an order left Cancelled by the merchant/expiry
     * sweep, whose Payment nonetheless later succeeded — flagged by
     * StripePaymentWebhookService's G3-A alarm). An ordinary Cancelled
     * order (any other status_reason, or none) is deliberately NOT
     * eligible here — the caller's separate "a Succeeded Payment exists"
     * check still applies unconditionally after this, regardless of which
     * branch matched.
     */
    private function isRefundableOrderState(Order $order): bool
    {
        return in_array($order->status, self::REFUNDABLE_ORDER_STATUSES, true)
            || ($order->status === OrderStatus::Cancelled
                && $order->status_reason === self::G3B_CLOSURE_ALARM_REASON);
    }

    /**
     * Deliberately separate from isRefundableOrderState() above, not a
     * third OR-branch on it — that method's eligibility is paired with a
     * PaymentStatus::Succeeded lookup in refund(), which this case can
     * never satisfy. Keeping the two checks in separate methods, each
     * paired with its own Payment lookup in its own calling method, is
     * what keeps refund()'s existing REFUNDABLE_ORDER_STATUSES/G3-B surface
     * completely unbroadened by this addition.
     */
    private function isExpirySweepLateSuccessState(Order $order): bool
    {
        return $order->status === OrderStatus::Cancelled
            && $order->status_reason === self::EXPIRY_SWEEP_ALARM_REASON;
    }
}
