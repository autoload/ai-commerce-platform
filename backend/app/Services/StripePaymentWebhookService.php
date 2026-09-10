<?php

namespace App\Services;

use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\UnknownPaymentIntentException;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StripeWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * database-design.md §4 (Database Design 2.6), STEP 3C: the single
 * transactional home for every Stripe webhook event this application
 * acts on — originally payment_intent.* only, extended in Phase 9D to
 * also own refund.created/refund.updated. StripeWebhookController has
 * already verified the event's signature before this is ever called —
 * nothing here re-derives trust from the event payload itself; Stripe's
 * own ids (PaymentIntent id, Refund id) are used purely as lookup keys
 * into the local payments/refunds tables, and every other identity fact
 * (organization_id, store_id, order_id) comes from the local rows once
 * located, never from the event's own metadata.
 *
 * One Payment = one payment attempt (§717). Terminal statuses
 * (Succeeded/Failed/Canceled) never regress or re-transition — a later
 * event describing an already-terminal Payment (or, for refunds, an
 * already-terminal Refund) is a no-op, though the event itself is still
 * durably recorded. Retry-payment (a brand-new Payment + PaymentIntent
 * per attempt) is Phase 5, out of scope here — this service never
 * revives a terminal Payment or Refund.
 */
class StripePaymentWebhookService
{
    /**
     * The minimal, deliberately narrow supported surface — see STEP 3C
     * design review for why each is needed. Every other Stripe event type
     * is recorded (for the durable dedup/audit trail) but never acted
     * upon.
     */
    private const SUPPORTED_EVENT_TYPES = [
        'payment_intent.processing',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
    ];

    /**
     * Phase 9D: charge.refunded is deliberately NOT used — Stripe's own
     * event-type reference states "Listen to refund.created for
     * information about the refund," and both of these deliver
     * event.data.object as a Refund directly (id/payment_intent/status
     * immediately available), unlike charge.refunded's Charge object,
     * which would require parsing a nested, expandable `refunds` list.
     */
    private const SUPPORTED_REFUND_EVENT_TYPES = [
        'refund.created',
        'refund.updated',
    ];

    private const TERMINAL_STATUSES = [
        PaymentStatus::Succeeded,
        PaymentStatus::Failed,
        PaymentStatus::Canceled,
    ];

    private const TERMINAL_REFUND_STATUSES = [
        RefundStatus::Succeeded,
        RefundStatus::Failed,
    ];

    private const REFUNDABLE_ORDER_STATUSES = [
        OrderStatus::Paid,
        OrderStatus::Processing,
        OrderStatus::Shipped,
        OrderStatus::Completed,
    ];

    /**
     * Phase 9E-1 (G3-A) — database-design.md §14's "logged for manual
     * reconciliation" promise, implemented. Set on Order.status_reason
     * when a payment_intent.succeeded webhook lands for an Order that has
     * already left Pending (merchant-cancelled, or the expiry-sweep race
     * §14 documents as an accepted, bounded residual risk). Payment is
     * still recorded as Succeeded — Stripe is always the source of truth
     * for Payment status — but the Order is never reopened and inventory
     * is never touched: G3-A is detection/alerting only.
     */
    private const PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON = 'payment_succeeded_after_closure';

    /**
     * Phase 9E-2 (G3-B) — the "resolved" counterpart to the G3-A alarm
     * above. Written only when a G3-B compensation refund (initiated
     * through RefundService's own narrow, explicitly-named eligibility
     * carve-out — never automatically) actually reaches Succeeded; see
     * transitionOrderToRefunded()'s G3-B branch.
     */
    private const PAYMENT_REFUNDED_AFTER_CLOSURE_REASON = 'payment_refunded_after_closure';

    /**
     * Phase 9E-3 — a narrower, separate alarm from G3-A's own
     * PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON above. `'expired'` is
     * PaymentExpirySweepService's own hardcoded failure_reason literal —
     * the one signal, already populated, that distinguishes "this Payment
     * was canceled by our expiry sweep" from any genuinely Stripe-reported
     * cancellation. This branch deliberately does NOT transition
     * Payment.status back to Succeeded (preserving the terminal-status
     * invariant every other Payment/Refund transition in this class
     * already relies on) — it is detection/alerting only, requiring
     * manual reconciliation, same posture as G3-A. Never reuse
     * PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON for this case: that value's
     * established meaning implies a locally Succeeded Payment (and is
     * what makes an order G3-B-eligible), which is never true here.
     */
    private const PAYMENT_SUCCEEDED_AFTER_EXPIRY_CANCELLATION_REASON = 'payment_succeeded_after_expiry_cancellation';

    public function __construct(
        private readonly InventoryAdjustmentService $inventoryAdjustmentService,
    ) {}

    /**
     * @return bool true if this event was newly processed; false if it was
     *              an already-seen duplicate delivery (both cases are a
     *              successful outcome from the caller's perspective — the
     *              distinction is informational only)
     *
     * @throws UnknownPaymentIntentException if a supported event's PaymentIntent
     *                                       has no matching local Payment — the
     *                                       caller must map this to a retryable
     *                                       HTTP status, never 2xx
     */
    public function process(Event $event): bool
    {
        try {
            DB::transaction(function () use ($event) {
                $this->recordEvent($event);

                if (in_array($event->type, self::SUPPORTED_EVENT_TYPES, true)) {
                    $this->applyPaymentIntentEvent($event);
                } elseif (in_array($event->type, self::SUPPORTED_REFUND_EVENT_TYPES, true)) {
                    $this->applyRefundEvent($event);
                }
            });

            return true;
        } catch (QueryException $e) {
            if ($this->isDuplicateEventViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * The atomic idempotency guard (database-design.md §4/§ Idempotency
     * Review): every event gets its own row here, in the same transaction
     * as any resulting state change, before anything else is attempted.
     * processed_at is NOT NULL on this table, so it's set here rather
     * than left to a later "actually finished" step — correctness is
     * guaranteed by the whole transaction committing or rolling back
     * together, not by processed_at's precise meaning.
     */
    private function recordEvent(Event $event): void
    {
        $record = new StripeWebhookEvent;
        $record->stripe_event_id = $event->id;
        $record->type = $event->type;
        $record->processed_at = now();
        $record->payload = $event->toArray();
        $record->save();
    }

    /**
     * @throws UnknownPaymentIntentException
     */
    private function applyPaymentIntentEvent(Event $event): void
    {
        $stripePaymentIntentId = $event->data->object->id;

        /** @var Payment|null $payment */
        $payment = Payment::where('stripe_payment_intent_id', $stripePaymentIntentId)
            ->lockForUpdate()
            ->first();

        if (! $payment) {
            throw new UnknownPaymentIntentException($stripePaymentIntentId);
        }

        match ($event->type) {
            'payment_intent.processing' => $this->handleProcessing($payment),
            'payment_intent.succeeded' => $this->handleSucceeded($payment),
            'payment_intent.payment_failed' => $this->handleTerminalFailure($payment, PaymentStatus::Failed, $event),
            'payment_intent.canceled' => $this->handleTerminalFailure($payment, PaymentStatus::Canceled, $event),
        };
    }

    /**
     * Phase 9D. event->data->object is a Stripe\Refund directly (per
     * refund.created/refund.updated's documented shape) — payment_intent,
     * id, and status are all immediately available, no Charge-object
     * indirection. Dashboard-initiated refunds (no prior local Refund row)
     * are found-or-created here rather than only updated, per the
     * approved design.
     *
     * @throws UnknownPaymentIntentException
     */
    private function applyRefundEvent(Event $event): void
    {
        $stripeRefund = $event->data->object;

        /** @var Payment|null $payment */
        $payment = Payment::where('stripe_payment_intent_id', $stripeRefund->payment_intent)
            ->lockForUpdate()
            ->first();

        if (! $payment) {
            throw new UnknownPaymentIntentException((string) $stripeRefund->payment_intent);
        }

        /** @var Refund|null $refund */
        $refund = Refund::where('stripe_refund_id', $stripeRefund->id)
            ->lockForUpdate()
            ->first();

        if (! $refund) {
            $refund = new Refund;
            $refund->organization_id = $payment->organization_id;
            $refund->store_id = $payment->store_id;
            $refund->order_id = $payment->order_id;
            $refund->payment_id = $payment->id;
            $refund->initiated_by_user_id = null;
            $refund->stripe_refund_id = $stripeRefund->id;
            $refund->amount = round($stripeRefund->amount / 100, 2);
            $refund->reason = $stripeRefund->reason;
            $refund->status = RefundStatus::Pending;
            $refund->save();
        }

        // Terminal guard: must work even if the Stripe event id differs
        // from whatever previously resolved this Refund to a terminal
        // state — this check is keyed on the Refund row itself, not on
        // event identity.
        if (in_array($refund->status, self::TERMINAL_REFUND_STATUSES, true)) {
            return;
        }

        $localStatus = $this->mapStripeRefundStatus($stripeRefund->status);

        if ($localStatus === null) {
            Log::error('Refund webhook: unrecognized Stripe refund status — Refund left unchanged.', [
                'refund_id' => $refund->id,
                'stripe_refund_id' => $stripeRefund->id,
                'stripe_status' => $stripeRefund->status,
            ]);

            return;
        }

        if ($localStatus === $refund->status) {
            // e.g. a repeat "pending"/"requires_action" delivery — no
            // transition to make, nothing further to do.
            return;
        }

        $refund->status = $localStatus;
        $refund->save();

        if ($localStatus === RefundStatus::Succeeded) {
            $this->transitionOrderToRefunded($refund);
            $this->restoreInventoryForRefund($payment, $refund);
        }
    }

    /**
     * Explicit match, never RefundStatus::from($stripeStatus) — a bare
     * ::from() call would throw an uncaught ValueError for
     * requires_action/canceled, neither of which is a valid backing
     * value on the local 3-case enum. requires_action collapses to
     * Pending (non-terminal, still in progress — practically unreachable
     * given this MVP is card-only); canceled collapses to Failed
     * (terminal, non-success). An unrecognized value (a future Stripe
     * addition) returns null, handled by the caller as a logged no-op —
     * never guessed, never silently assigned.
     */
    private function mapStripeRefundStatus(?string $stripeStatus): ?RefundStatus
    {
        return match ($stripeStatus) {
            'pending', 'requires_action' => RefundStatus::Pending,
            'succeeded' => RefundStatus::Succeeded,
            'failed', 'canceled' => RefundStatus::Failed,
            default => null,
        };
    }

    /**
     * The sole trigger for Order -> refunded, and only from one of the
     * four documented source statuses — never a regression, and never
     * forced if the order has since moved to some other defensive state
     * (logged, not silently overwritten).
     *
     * Phase 9E-2 (G3-B) carve-out: a Cancelled order whose status_reason
     * is exactly the G3-A alarm value is the one deliberate exception —
     * its successful compensation refund must NOT reopen the order (it
     * stays Cancelled, matching G3-A's own "never reopen" invariant) but
     * DOES need status_reason resolved to a distinct "handled" value, so
     * this no longer reads as a still-open alarm. Every other non-
     * refundable-status order (an ordinary Cancelled order, or any other
     * terminal state) falls through to the pre-existing, unmodified
     * warning-and-no-op path below.
     */
    private function transitionOrderToRefunded(Refund $refund): void
    {
        /** @var Order $order */
        $order = Order::where('id', $refund->order_id)
            ->lockForUpdate()
            ->first();

        if (! in_array($order->status, self::REFUNDABLE_ORDER_STATUSES, true)) {
            if ($order->status === OrderStatus::Cancelled
                && $order->status_reason === self::PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON) {
                $order->status_reason = self::PAYMENT_REFUNDED_AFTER_CLOSURE_REASON;
                $order->save();

                Log::info('G3-B compensation refund succeeded — order remains cancelled, status_reason resolved.', [
                    'order_id' => $order->id,
                    'refund_id' => $refund->id,
                    'payment_id' => $refund->payment_id,
                    'store_id' => $order->store_id,
                    'organization_id' => $order->organization_id,
                ]);

                return;
            }

            Log::warning('Refund succeeded but order was not in a refundable status — order left unchanged.', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'order_status' => $order->status->value,
            ]);

            return;
        }

        $order->status = OrderStatus::Refunded;
        $order->save();
    }

    /**
     * Driven by this payment's own checkout ledger rows, mirroring
     * releaseInventoryForPayment()'s exact shape (reason swapped to
     * Refund). For the normal Phase 9D case, a payment eligible for
     * refund has, by definition, succeeded, so it can never have a prior
     * `release` row (release only ever fires on payment failure/
     * cancellation, neither of which is compatible with that same Payment
     * later reaching Succeeded). Idempotency is fully covered by
     * inventory_transactions' existing dedup_key mechanism — a second
     * attempt to insert the same (order_item_id, refund, payment_id)
     * combination fails the existing unique constraint, a defense-in-
     * depth backstop behind this method's own terminal-status guard
     * (applyRefundEvent() never calls this twice for the same Refund).
     *
     * Phase 9E-2 (G3-B) is the one real exception to "can never have a
     * prior release row": a G3-B compensation refund's Payment did
     * succeed, but only *after* its Order was already cancelled — meaning
     * cancellation/expiry already inserted a `release` row for every
     * claim before this refund could ever be initiated. Re-crediting
     * those claims here on top of that release would double-credit
     * inventory that was never actually withheld a second time. Each
     * claim is checked independently (per order_item_id, not once per
     * payment) against inventory_transactions' own dedup_key invariant —
     * at most one `release` row can ever exist for a given (order_item_id,
     * payment_id) pair, so this check is unambiguous. For the normal
     * case this is always false (per the paragraph above), so this is a
     * pure safety addition with zero behavior change to any existing,
     * already-tested refund.
     */
    private function restoreInventoryForRefund(Payment $payment, Refund $refund): void
    {
        $claims = InventoryTransaction::where('payment_id', $payment->id)
            ->where('reason', InventoryTransactionReason::Checkout)
            ->get();

        foreach ($claims as $claim) {
            $alreadyReleased = InventoryTransaction::where('order_item_id', $claim->order_item_id)
                ->where('payment_id', $payment->id)
                ->where('reason', InventoryTransactionReason::Release)
                ->exists();

            if ($alreadyReleased) {
                Log::info('Refund inventory restoration skipped — this claim was already released (G3-B: order was cancelled before its late payment succeeded).', [
                    'order_item_id' => $claim->order_item_id,
                    'payment_id' => $payment->id,
                    'checkout_transaction_id' => $claim->id,
                    'refund_id' => $refund->id,
                ]);

                continue;
            }

            $this->inventoryAdjustmentService->adjust(
                $claim->variant,
                abs($claim->delta),
                InventoryTransactionReason::Refund,
                null,
                null,
                $claim->orderItem,
                $payment,
            );
        }
    }

    private function handleProcessing(Payment $payment): void
    {
        if (in_array($payment->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $payment->status = PaymentStatus::Processing;
        $payment->save();
    }

    private function handleSucceeded(Payment $payment): void
    {
        // Phase 9E-3 — must be checked BEFORE the blanket terminal-status
        // guard immediately below, since Canceled is itself one of
        // TERMINAL_STATUSES: this narrow carve-out (expiry-sweep-canceled
        // Payment, genuinely later succeeded on Stripe's side) would
        // otherwise be silently absorbed by that guard and never reached.
        if ($this->isExpirySweepCanceledPayment($payment)) {
            $this->recordLatePaymentSucceededAfterExpirySweep($payment);

            return;
        }

        if (in_array($payment->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $payment->status = PaymentStatus::Succeeded;
        $payment->save();

        $this->transitionOrderToPaid($payment);
    }

    private function handleTerminalFailure(Payment $payment, PaymentStatus $to, Event $event): void
    {
        if (in_array($payment->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $payment->status = $to;
        $payment->failure_reason = $this->extractFailureReason($event);
        $payment->save();

        $this->releaseInventoryForPayment($payment);
    }

    /**
     * The only event that can move an order out of pending, and only if
     * it's still pending at the moment of this (locked) check — never a
     * regression of any other order status. This is a webhook-specific
     * transition, deliberately not routed through
     * MerchantOrderStatusTransitions/OrderStatusUpdateService, which are
     * scoped to merchant-triggered edges only and do not (and should not)
     * whitelist this system-only one.
     */
    private function transitionOrderToPaid(Payment $payment): void
    {
        /** @var Order $order */
        $order = Order::where('id', $payment->order_id)
            ->lockForUpdate()
            ->first();

        if ($order->status !== OrderStatus::Pending) {
            $this->recordPaymentSucceededAfterClosure($order, $payment);

            return;
        }

        $order->status = OrderStatus::Paid;
        $order->paid_at = now();
        $order->save();
    }

    /**
     * Phase 9E-1 (G3-A). Fires only when a genuinely new Payment
     * transition into Succeeded lands on an Order that has already left
     * Pending — never on a routine pending->paid success. Detection/
     * alerting only: Order.status is never changed here and no inventory
     * mutation is attempted, per the approved G3-A design.
     *
     * Idempotency is structural, not a new mechanism: this method is only
     * ever reached once per Payment, because handleSucceeded()'s own
     * terminal-status guard (TERMINAL_STATUSES) short-circuits before
     * calling transitionOrderToPaid() again on any later delivery for a
     * Payment already Succeeded — exact-event-id redelivery is separately
     * blocked further upstream by stripe_webhook_events' unique
     * stripe_event_id. The status_reason equality check below is a third,
     * redundant guard purely for defense-in-depth, matching this class's
     * existing style of stacking cheap extra checks even where a stronger
     * guarantee already exists elsewhere.
     */
    private function recordPaymentSucceededAfterClosure(Order $order, Payment $payment): void
    {
        if ($order->status_reason === self::PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON) {
            return;
        }

        $previousStatusReason = $order->status_reason;

        $order->status_reason = self::PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON;
        $order->save();

        Log::critical('Payment succeeded for an Order that had already left Pending — payment and order are now inconsistent and require manual reconciliation.', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'store_id' => $order->store_id,
            'organization_id' => $order->organization_id,
            'order_status' => $order->status->value,
            'previous_status_reason' => $previousStatusReason,
        ]);
    }

    /**
     * Phase 9E-3. `'expired'` is PaymentExpirySweepService's own
     * hardcoded failure_reason literal — the only place in this codebase
     * that ever writes it — so this pairing (Canceled + failure_reason
     * 'expired') unambiguously identifies "canceled by our expiry sweep,"
     * as opposed to any genuinely Stripe-reported cancellation/failure
     * (which would carry Stripe's own cancellation_reason/error message
     * here instead). Deliberately narrow: does not fire for a Canceled
     * Payment with any other failure_reason.
     */
    private function isExpirySweepCanceledPayment(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Canceled
            && $payment->failure_reason === 'expired';
    }

    /**
     * Phase 9E-3 — G3-A extension covering the expiry-sweep race
     * database-design.md §14 names explicitly (PaymentExpirySweepService
     * marks Payment Canceled in the same transaction it releases
     * inventory and cancels the Order, so a genuinely later
     * payment_intent.succeeded would otherwise be silently absorbed by
     * handleSucceeded()'s blanket terminal-status guard before G3-A's own
     * alarm logic could ever run).
     *
     * This branch intentionally PRESERVES the terminal Payment-status
     * invariant this class relies on everywhere else: Payment.status
     * stays Canceled, never reverts to Succeeded. It is detection/
     * alerting only, exactly like G3-A — no inventory mutation, no
     * Stripe call, no Order reopening, no Refund. A human must reconcile
     * this manually (in Stripe's own dashboard); this increment does not
     * attempt to correct the local Payment record itself.
     *
     * Uses a distinct status_reason from G3-A's own
     * PAYMENT_SUCCEEDED_AFTER_CLOSURE_REASON specifically because that
     * value's established meaning implies a locally Succeeded Payment
     * (and is what RefundService's G3-B eligibility carve-out keys on) —
     * never true here, so reusing it would make the same string mean two
     * different underlying Payment states.
     *
     * Idempotency: unlike G3-A, this branch never transitions
     * Payment.status, so it does not inherit a second guard "for free"
     * from a Payment terminal-status change — the status_reason equality
     * check below is the sole mechanism preventing a re-log/re-write on a
     * differently-event-id'd redelivery. Exact-event-id redelivery is
     * still independently blocked further upstream by
     * stripe_webhook_events' unique stripe_event_id.
     */
    private function recordLatePaymentSucceededAfterExpirySweep(Payment $payment): void
    {
        /** @var Order $order */
        $order = Order::where('id', $payment->order_id)
            ->lockForUpdate()
            ->first();

        if ($order->status_reason === self::PAYMENT_SUCCEEDED_AFTER_EXPIRY_CANCELLATION_REASON) {
            return;
        }

        $previousStatusReason = $order->status_reason;

        $order->status_reason = self::PAYMENT_SUCCEEDED_AFTER_EXPIRY_CANCELLATION_REASON;
        $order->save();

        Log::critical('Payment succeeded on Stripe for a Payment already marked Canceled by the expiry sweep — Payment and Stripe state have diverged and require manual reconciliation.', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'store_id' => $order->store_id,
            'organization_id' => $order->organization_id,
            'order_status' => $order->status->value,
            'payment_status' => $payment->status->value,
            'previous_status_reason' => $previousStatusReason,
            'payment_expired' => true,
        ]);
    }

    /**
     * Driven entirely by this Payment's own checkout ledger rows — never
     * by $order->items. order_items carries no payment_id of its own;
     * order_items and "what this specific payment attempt claimed" are
     * only equivalent today because retry-payment (a second Payment for
     * the same Order) doesn't exist yet. Querying inventory_transactions
     * for payment_id = $payment->id keeps this correct once it does,
     * with no rework needed here.
     *
     * Reuses InventoryAdjustmentService exactly as-is (no changes) — its
     * existing $orderItem/$payment params already produce the correct
     * order_id/order_item_id/payment_id linkage and dedup_key for a
     * release row.
     */
    private function releaseInventoryForPayment(Payment $payment): void
    {
        $claims = InventoryTransaction::where('payment_id', $payment->id)
            ->where('reason', InventoryTransactionReason::Checkout)
            ->get();

        foreach ($claims as $claim) {
            $this->inventoryAdjustmentService->adjust(
                $claim->variant,
                abs($claim->delta),
                InventoryTransactionReason::Release,
                null,
                null,
                $claim->orderItem,
                $payment,
            );
        }
    }

    /**
     * payments.failure_reason is a single varchar(255) shared by both
     * Failed and Canceled outcomes. For a cancellation, Stripe's own
     * cancellation_reason is the more directly relevant field when
     * present; last_payment_error.message is the fallback for both event
     * types (a cancellation can also carry a trailing payment error, and
     * a failure always should).
     */
    private function extractFailureReason(Event $event): ?string
    {
        $paymentIntent = $event->data->object;

        if ($event->type === 'payment_intent.canceled' && $paymentIntent->cancellation_reason) {
            return (string) $paymentIntent->cancellation_reason;
        }

        return $paymentIntent->last_payment_error?->message ?? null;
    }

    private function isDuplicateEventViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'stripe_webhook_events_stripe_event_id_unique');
    }
}
