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
}
