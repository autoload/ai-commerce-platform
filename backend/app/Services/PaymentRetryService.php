<?php

namespace App\Services;

use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ActivePaymentExistsException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\OrderNotEligibleForRetryException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;

/**
 * Phase 3 STEP 3D — the single mutation path for a customer-initiated
 * payment retry. "One Payment = one payment attempt": a retry always
 * creates a brand-new Payment + new Stripe PaymentIntent, never revives a
 * terminal one. Zero changes to STEP 3A/3B/3C — CheckoutOrderCreationService,
 * InventoryAdjustmentService, StripePaymentIntentGateway,
 * StripePaymentWebhookService, OrderStatusUpdateService, and
 * MerchantOrderStatusTransitions are all reused/left exactly as-is.
 *
 * Concurrency model (approved design, deliberately breaking this project's
 * own general "never hold a lock across a network call" rule for this one
 * case): the `orders` row lock is held across the ENTIRE sequence,
 * including the Stripe API call — not released and reacquired around it.
 * This is what makes "at most one active Payment per Order" an actual
 * invariant rather than a best-effort check: without it, two concurrent
 * retries with different idempotency keys could both pass the eligibility
 * check and both call Stripe before either's Payment row exists to signal
 * "already in progress" to the other.
 *
 * Same-key replay does NOT short-circuit before the Stripe call (unlike an
 * earlier draft of this design). It reaches Stripe with the same derived
 * Stripe idempotency key every time — Stripe's own idempotency cache
 * returns the identical PaymentIntent/client_secret — exactly mirroring
 * CheckoutOrderCreationService/CheckoutController's established pattern.
 * The `payments` table's own `unique(order_id, idempotency_key)`
 * constraint is what actually prevents a duplicate Payment row, caught
 * here the same way CheckoutOrderCreationService::isIdempotencyKeyViolation()
 * catches its own analogous violation.
 */
class PaymentRetryService
{
    private const ACTIVE_PAYMENT_STATUSES = [
        PaymentStatus::RequiresPayment,
        PaymentStatus::Processing,
    ];

    private const TERMINAL_PAYMENT_STATUSES = [
        PaymentStatus::Failed,
        PaymentStatus::Canceled,
    ];

    public function __construct(
        private readonly StripePaymentIntentGateway $paymentIntentGateway,
        private readonly InventoryAdjustmentService $inventoryAdjustmentService,
    ) {}

    /**
     * @return array{order: Order, payment: Payment, payment_intent: PaymentIntent, is_new: bool}
     *
     * @throws OrderNotEligibleForRetryException if the order isn't Pending, or has no prior terminal Payment
     * @throws ActivePaymentExistsException if a different retry attempt is already active for this order
     * @throws InsufficientInventoryException if the re-claim fails — the order has already been
     *                                        cancelled (committed) by the time this is thrown
     */
    public function retry(Order $order, string $idempotencyKey): array
    {
        $result = DB::transaction(function () use ($order, $idempotencyKey) {
            /** @var Order $locked */
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($locked->status !== OrderStatus::Pending) {
                throw new OrderNotEligibleForRetryException($locked, 'order is not pending');
            }

            /** @var Payment|null $activePayment */
            $activePayment = Payment::where('order_id', $locked->id)
                ->whereIn('status', self::ACTIVE_PAYMENT_STATUSES)
                ->first();

            // An active Payment carrying this exact idempotency key is a
            // replay of this same retry request, not a conflict — let it
            // fall through to the Stripe call below instead of rejecting.
            if ($activePayment && $activePayment->idempotency_key !== $idempotencyKey) {
                throw new ActivePaymentExistsException($locked);
            }

            $hasTerminalPayment = Payment::where('order_id', $locked->id)
                ->whereIn('status', self::TERMINAL_PAYMENT_STATUSES)
                ->exists();

            if (! $hasTerminalPayment) {
                throw new OrderNotEligibleForRetryException(
                    $locked,
                    'no prior failed or canceled payment attempt exists to retry'
                );
            }

            $this->logAdvisoryInventoryGlance($locked);

            $stripeIdempotencyKey = hash('sha256', "retry:{$locked->id}:{$idempotencyKey}");

            $paymentIntent = $this->paymentIntentGateway->create([
                'amount' => (int) round(((float) $locked->total) * 100),
                'currency' => $locked->currency,
                'payment_method_types' => ['card'],
                'metadata' => [
                    'organization_id' => (string) $locked->organization_id,
                    'store_id' => (string) $locked->store_id,
                    'customer_id' => (string) $locked->customer_id,
                    'order_id' => (string) $locked->id,
                    'idempotency_key' => $idempotencyKey,
                ],
            ], $stripeIdempotencyKey);

            try {
                $payment = DB::transaction(function () use ($locked, $paymentIntent, $idempotencyKey) {
                    $payment = new Payment;
                    $payment->organization_id = $locked->organization_id;
                    $payment->store_id = $locked->store_id;
                    $payment->order_id = $locked->id;
                    $payment->stripe_payment_intent_id = $paymentIntent->id;
                    $payment->idempotency_key = $idempotencyKey;
                    $payment->status = PaymentStatus::RequiresPayment;
                    $payment->amount = $locked->total;
                    $payment->currency = $locked->currency;
                    $payment->save();

                    foreach ($locked->items as $item) {
                        // The locked claim itself — reused completely
                        // unmodified, same as STEP 3A's checkout claim.
                        $this->inventoryAdjustmentService->adjust(
                            $item->variant,
                            -$item->quantity,
                            InventoryTransactionReason::Checkout,
                            null,
                            null,
                            $item,
                            $payment,
                        );
                    }

                    return $payment;
                });

                return ['order' => $locked, 'payment' => $payment, 'payment_intent' => $paymentIntent, 'is_new' => true, 'insufficient_inventory' => null];
            } catch (QueryException $e) {
                if (! $this->isDuplicateEntryViolation($e)) {
                    throw $e;
                }

                // Resolve by looking up the row itself rather than by
                // matching a specific constraint name in the error
                // message: a genuine same-key replay sends Stripe the
                // identical derived idempotency key, so Stripe returns the
                // identical PaymentIntent — meaning the resulting INSERT
                // collides on `stripe_payment_intent_id` (an older index)
                // just as much as on `(order_id, idempotency_key)`, and
                // MySQL only reports whichever one it happens to check
                // first. This lookup answers the question that actually
                // matters — "does a Payment for this exact retry request
                // already exist?" — independent of which index fired.
                $existing = Payment::where('order_id', $locked->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return ['order' => $locked, 'payment' => $existing, 'payment_intent' => $paymentIntent, 'is_new' => false, 'insufficient_inventory' => null];
                }

                // No matching row for this exact request — a genuine
                // conflict this service cannot resolve on its own, most
                // plausibly the structurally near-impossible
                // stripe_payment_intent_id collision the approved design
                // explicitly calls out. Mapped to 409 rather than an
                // opaque 500.
                throw new ActivePaymentExistsException($locked);
            } catch (InsufficientInventoryException $e) {
                // The nested transaction's SAVEPOINT has already rolled
                // back the attempted Payment insert and any inventory
                // claim made so far — the outer lock/transaction survive
                // untouched. Committing the cancellation here, not
                // rethrowing yet, is what makes it durable: rethrowing
                // inside this closure would roll back the outer
                // transaction too, undoing the cancellation itself.
                $locked->status = OrderStatus::Cancelled;
                $locked->status_reason = 'item_no_longer_available';
                $locked->cancelled_at = now();
                $locked->save();

                return ['order' => $locked, 'payment' => null, 'payment_intent' => $paymentIntent, 'is_new' => false, 'insufficient_inventory' => $e];
            }
        });

        // Rethrown only after the outer transaction has committed the
        // cancellation, purely to signal the caller — never rolls anything
        // back at this point.
        if ($result['insufficient_inventory'] !== null) {
            throw $result['insufficient_inventory'];
        }

        return $result;
    }

    /**
     * Optional, advisory-only, non-authoritative per the approved design —
     * a plain read with no lock, logged purely for visibility. Never
     * blocks or throws: the locked claim inside
     * InventoryAdjustmentService::adjust() later in this same sequence is
     * the sole authoritative check.
     */
    private function logAdvisoryInventoryGlance(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = Inventory::where('product_variant_id', $item->product_variant_id)->first();
            $onHand = $inventory?->quantity_on_hand ?? 0;

            if ($onHand < $item->quantity) {
                Log::info('Payment retry: advisory inventory glance shows likely insufficient stock (non-authoritative, no lock held).', [
                    'order_id' => $order->id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity_needed' => $item->quantity,
                    'quantity_on_hand' => $onHand,
                ]);
            }
        }
    }

    /**
     * Narrow detection of MySQL error 1062 ("Duplicate entry") only —
     * mirrors the equally narrow 1205 ("Lock wait timeout") detection this
     * design requires elsewhere. Which specific unique index fired is
     * deliberately not inspected here; the catch block above resolves the
     * ambiguity by looking up the row itself instead.
     */
    private function isDuplicateEntryViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
