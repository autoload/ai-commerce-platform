<?php

namespace App\Services;

use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Throwable;

/**
 * Phase 6 — database-design.md §12 ("Expiry Semantics"). The scheduled
 * counterpart to StripePaymentWebhookService: cancels a `pending` Order
 * whose *current* Payment has sat at `requires_payment` (never
 * `processing`) longer than the configured window, releasing its claimed
 * inventory exactly as a failed/canceled webhook already would.
 *
 * Anchored to the Payment's own created_at, never the Order's — a retry
 * legitimately restarts the window, since it must first re-win the
 * inventory claim (§9/§10) rather than reuse a stale one.
 *
 * Locking discipline mirrors StripePaymentWebhookService's own lock order
 * (Payment, then conditionally Order) rather than PaymentRetryService's
 * (Order only) — both this service and the webhook handler must be able
 * to run concurrently against the same Payment/Order pair without lock
 * inversion (§12's "the sweep and the webhook handler must both lock the
 * same orders row before making a state decision").
 *
 * The live Stripe retrieve() guard is deliberately performed *inside* the
 * held lock/transaction, the same accepted deviation from this project's
 * general "never hold a lock across a network call" rule that
 * PaymentRetryService already makes for its own Stripe call — releasing
 * the lock around retrieve() would widen, not narrow, the residual race
 * §14 already documents as accepted-but-not-eliminated. The best-effort
 * PaymentIntent::cancel() call happens only after the local transaction
 * has already committed — it never gates or reverses the local decision.
 */
class PaymentExpirySweepService
{
    /**
     * Stripe PaymentIntent statuses that confirm the attempt was genuinely
     * abandoned — safe to cancel locally. Any other status (most notably
     * `processing`/`requires_capture`/`succeeded`) means Stripe's side has
     * moved on and the corresponding webhook simply hasn't arrived yet;
     * the sweep must defer to that webhook rather than act on stale data.
     */
    private const ABANDONED_STRIPE_STATUSES = [
        'requires_payment_method',
        'requires_confirmation',
        'requires_action',
        'canceled',
    ];

    public function __construct(
        private readonly StripePaymentIntentGateway $paymentIntentGateway,
        private readonly InventoryAdjustmentService $inventoryAdjustmentService,
    ) {}

    /**
     * @return array{processed: int, cancelled: int, deferred: int, skipped: int, errors: int}
     */
    public function sweep(): array
    {
        $windowMinutes = (int) config('services.stripe.checkout_expiry_minutes');
        $cutoff = CarbonImmutable::now()->subMinutes($windowMinutes);

        $counts = ['processed' => 0, 'cancelled' => 0, 'deferred' => 0, 'skipped' => 0, 'errors' => 0];

        Payment::where('status', PaymentStatus::RequiresPayment)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($candidates) use ($cutoff, &$counts) {
                foreach ($candidates as $candidate) {
                    $counts['processed']++;

                    try {
                        $counts[$this->processCandidate($candidate->id, $cutoff)]++;
                    } catch (Throwable $e) {
                        $counts['errors']++;
                        Log::error('Payment expiry sweep: failed to process candidate.', [
                            'payment_id' => $candidate->id,
                            'order_id' => $candidate->order_id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $counts;
    }

    /**
     * @return 'cancelled'|'deferred'|'skipped'
     */
    private function processCandidate(int $paymentId, CarbonImmutable $cutoff): string
    {
        $outcome = DB::transaction(function () use ($paymentId, $cutoff) {
            /** @var Payment|null $payment */
            $payment = Payment::where('id', $paymentId)->lockForUpdate()->first();

            // Re-verify under lock — a webhook may have raced ahead (or a
            // prior sweep chunk may have already handled it) between the
            // candidate query above and this lock being acquired. This is
            // what makes re-running the sweep safe/idempotent.
            if (! $payment || $payment->status !== PaymentStatus::RequiresPayment || $payment->created_at->gt($cutoff)) {
                return 'skipped';
            }

            /** @var Order $order */
            $order = Order::where('id', $payment->order_id)->lockForUpdate()->first();

            if ($order->status !== OrderStatus::Pending) {
                return 'skipped';
            }

            // Live guard (§12) — held inside this same lock/transaction
            // deliberately; see the class docblock for why.
            $paymentIntent = $this->paymentIntentGateway->retrieve($payment->stripe_payment_intent_id);

            if (! $this->isAbandoned($paymentIntent)) {
                return 'deferred';
            }

            $this->releaseInventoryForPayment($payment);

            $payment->status = PaymentStatus::Canceled;
            $payment->failure_reason = 'expired';
            $payment->save();

            $order->status = OrderStatus::Cancelled;
            $order->status_reason = 'expired';
            $order->cancelled_at = now();
            $order->save();

            return 'cancelled';
        });

        if ($outcome === 'cancelled') {
            $this->bestEffortCancelPaymentIntent(Payment::find($paymentId));
        }

        return $outcome;
    }

    private function isAbandoned(PaymentIntent $paymentIntent): bool
    {
        return in_array($paymentIntent->status, self::ABANDONED_STRIPE_STATUSES, true);
    }

    /**
     * Driven entirely by this Payment's own checkout ledger rows, the same
     * discipline StripePaymentWebhookService::releaseInventoryForPayment()
     * already established — never by $order->items, since a future retry
     * could add a second Payment (and second claim) against this Order.
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
     * Best-effort only (§12) — deliberately outside the transaction above,
     * after the local cancellation has already committed. Any failure
     * here (already canceled on Stripe's side, network error, or anything
     * else) must never undo or misreport the already-committed local
     * cancellation — caught broadly and only logged, deliberately wider
     * than just Stripe's own ApiErrorException.
     */
    private function bestEffortCancelPaymentIntent(?Payment $payment): void
    {
        if (! $payment) {
            return;
        }

        try {
            $this->paymentIntentGateway->cancel($payment->stripe_payment_intent_id);
        } catch (Throwable $e) {
            Log::warning('Payment expiry sweep: best-effort Stripe PaymentIntent cancellation failed.', [
                'payment_id' => $payment->id,
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
