<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-fixture infrastructure only (Phase 2 — inventory ledger dedup_key
 * and payments idempotency-key schema tests need real Payment rows) — no
 * PaymentIntent-creation feature exists yet.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Payment has no fillable attributes — every field is set directly in
     * forOrder() below, the same discipline as OrderFactory/OrderItemFactory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    public function forOrder(Order $order): static
    {
        return $this->afterMaking(function (Payment $payment) use ($order) {
            $payment->organization_id = $order->organization_id;
            $payment->store_id = $order->store_id;
            $payment->order_id = $order->id;
            $payment->stripe_payment_intent_id = 'pi_'.fake()->unique()->regexify('[a-zA-Z0-9]{24}');
            $payment->status = PaymentStatus::RequiresPayment;
            $payment->amount = $order->total;
            $payment->currency = $order->currency;
        });
    }
}
