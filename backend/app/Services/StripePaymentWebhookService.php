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
     */
    private function transitionOrderToRefunded(Refund $refund): void
    {
        /** @var Order $order */
        $order = Order::where('id', $refund->order_id)
            ->lockForUpdate()
            ->first();

        if (! in_array($order->status, self::REFUNDABLE_ORDER_STATUSES, true)) {
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
     * Refund) — a payment eligible for refund has, by definition,
     * succeeded, so it can never have a prior `release` row (release only
     * ever fires on payment failure/cancellation). Idempotency is fully
     * covered by inventory_transactions' existing dedup_key mechanism —
     * a second attempt to insert the same (order_item_id, refund,
     * payment_id) combination fails the existing unique constraint,
     * a defense-in-depth backstop behind this method's own terminal-
     * status guard (applyRefundEvent() never calls this twice for the
     * same Refund).
     */
    private function restoreInventoryForRefund(Payment $payment, Refund $refund): void
    {
        $claims = InventoryTransaction::where('payment_id', $payment->id)
            ->where('reason', InventoryTransactionReason::Checkout)
            ->get();

        foreach ($claims as $claim) {
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
            return;
        }

        $order->status = OrderStatus::Paid;
        $order->paid_at = now();
        $order->save();
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
