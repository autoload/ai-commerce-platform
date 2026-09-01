<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderAddressType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CustomerStoreMismatchException;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\ProductVariantUnavailableException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * database-design.md §9/§13 (Database Design 2.6): the atomic write at the
 * heart of Checkout. Creates the pending Order/OrderItems/OrderAddress/
 * Payment row and claims inventory for every line item, all as one single
 * DB transaction, so no partially-committed intermediate state (an order
 * with claimed inventory but no Payment linkage, say) can ever exist.
 *
 * Deliberately out of scope here, per the approved STEP 3A slice:
 * - Reading/validating the cart itself (Redis/localStorage) — this
 *   service takes already-resolved {variant, quantity} line items.
 * - Creating the Stripe PaymentIntent — this service takes an
 *   already-obtained $stripePaymentIntentId; §13's PaymentIntent-first
 *   sequencing means the caller must create it *before* calling this
 *   method, not the other way around.
 * - The retry-payment flow (§10) and expiry sweep (§12).
 *
 * Inventory is claimed by repeated calls to InventoryAdjustmentService —
 * the single locked mutation path already established in Block 4B — never
 * by re-implementing row locking here. Because those calls happen inside
 * this method's own DB::transaction(), Laravel nests them as savepoints:
 * an InsufficientInventoryException from any line item unwinds the entire
 * transaction, so a failed claim on item 2 also undoes the Order/Items/
 * Address/Payment rows and any claim already made for item 1.
 */
class CheckoutOrderCreationService
{
    public function __construct(
        private readonly InventoryAdjustmentService $inventoryAdjustmentService,
    ) {}

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $lineItems
     * @param  array{recipient_name: string, line1: string, line2?: ?string, city: string, state: string, postal_code: string, country: string, phone?: ?string}  $shippingAddress
     *
     * @throws CustomerStoreMismatchException if $customer doesn't belong to $store/$store's organization
     * @throws InsufficientInventoryException if any line item's claim would take quantity_on_hand negative
     * @throws ProductVariantUnavailableException if a variant doesn't belong to $store or isn't Active
     * @throws IdempotencyKeyConflictException if $idempotencyKey was already used with a different request
     */
    public function createPendingOrder(
        Customer $customer,
        Store $store,
        array $lineItems,
        array $shippingAddress,
        string $stripePaymentIntentId,
        ?string $idempotencyKey = null,
        ?string $idempotencyKeyPayloadHash = null,
        string $currency = 'usd',
    ): Order {
        $this->assertCustomerBelongsToStore($customer, $store);

        if ($lineItems === []) {
            throw new InvalidArgumentException('Checkout requires at least one line item.');
        }

        foreach ($lineItems as $lineItem) {
            $this->assertVariantIsCheckoutable($lineItem['variant'], $store);

            if ($lineItem['quantity'] < 1) {
                throw new InvalidArgumentException('Every line item quantity must be at least 1.');
            }
        }

        // Bounds lock-acquisition order across the line items so two
        // concurrent multi-item checkouts sharing overlapping variants
        // always attempt their claims in the same relative order —
        // avoiding a classic lock-ordering deadlock (A locks X then waits
        // on Y while B locks Y then waits on X).
        usort($lineItems, fn (array $a, array $b) => $a['variant']->id <=> $b['variant']->id);

        try {
            return DB::transaction(function () use ($customer, $store, $lineItems, $shippingAddress, $stripePaymentIntentId, $idempotencyKey, $idempotencyKeyPayloadHash, $currency) {
                $subtotal = round(array_reduce(
                    $lineItems,
                    fn (float $carry, array $lineItem) => $carry + ((float) $lineItem['variant']->price * $lineItem['quantity']),
                    0.0
                ), 2);

                $order = new Order;
                $order->organization_id = $store->organization_id;
                $order->store_id = $store->id;
                $order->customer_id = $customer->id;
                $order->idempotency_key = $idempotencyKey;
                $order->idempotency_key_payload_hash = $idempotencyKeyPayloadHash;
                $order->order_number = (string) Str::ulid();
                $order->status = OrderStatus::Pending;
                $order->subtotal = $subtotal;
                $order->discount_total = 0;
                $order->tax_total = 0;
                $order->total = $subtotal;
                $order->currency = $currency;
                $order->customer_name = $customer->name;
                $order->customer_email = $customer->email;
                $order->save();

                $address = new OrderAddress;
                $address->order_id = $order->id;
                $address->type = OrderAddressType::Shipping;
                $address->recipient_name = $shippingAddress['recipient_name'];
                $address->line1 = $shippingAddress['line1'];
                $address->line2 = $shippingAddress['line2'] ?? null;
                $address->city = $shippingAddress['city'];
                $address->state = $shippingAddress['state'];
                $address->postal_code = $shippingAddress['postal_code'];
                $address->country = $shippingAddress['country'];
                $address->phone = $shippingAddress['phone'] ?? null;
                $address->save();

                $payment = new Payment;
                $payment->organization_id = $store->organization_id;
                $payment->store_id = $store->id;
                $payment->order_id = $order->id;
                $payment->stripe_payment_intent_id = $stripePaymentIntentId;
                $payment->status = PaymentStatus::RequiresPayment;
                $payment->amount = $subtotal;
                $payment->currency = $currency;
                $payment->save();

                foreach ($lineItems as $lineItem) {
                    $variant = $lineItem['variant'];
                    $quantity = $lineItem['quantity'];
                    $lineTotal = round((float) $variant->price * $quantity, 2);

                    $item = new OrderItem;
                    $item->order_id = $order->id;
                    $item->product_id = $variant->product_id;
                    $item->product_variant_id = $variant->id;
                    $item->product_name = $variant->product->name;
                    $item->sku = $variant->sku;
                    $item->selected_options = null;
                    $item->unit_price = $variant->price;
                    $item->quantity = $quantity;
                    $item->line_total = $lineTotal;
                    $item->save();

                    // The locked claim itself — see class docblock for why
                    // this call, not a bespoke lock here, is correct.
                    $this->inventoryAdjustmentService->adjust(
                        $variant,
                        -$quantity,
                        InventoryTransactionReason::Checkout,
                        null,
                        null,
                        $item,
                        $payment,
                    );
                }

                return $order->load(['items', 'addresses', 'payments']);
            });
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isIdempotencyKeyViolation($e)) {
                return $this->resolveIdempotentOrder($customer, $idempotencyKey, $idempotencyKeyPayloadHash);
            }

            throw $e;
        }
    }

    /**
     * This service must remain safe even if invoked with a mismatched
     * Customer/Store pair — it does not trust a caller (a future
     * controller included) to have already guaranteed this.
     */
    private function assertCustomerBelongsToStore(Customer $customer, Store $store): void
    {
        if ($customer->store_id !== $store->id || $customer->organization_id !== $store->organization_id) {
            throw new CustomerStoreMismatchException($customer, $store);
        }
    }

    private function assertVariantIsCheckoutable(ProductVariant $variant, Store $store): void
    {
        if ($variant->store_id !== $store->id) {
            throw new ProductVariantUnavailableException($variant, 'does not belong to this store');
        }

        if ($variant->status !== CatalogStatus::Active) {
            throw new ProductVariantUnavailableException($variant, 'is not active');
        }
    }

    /**
     * database-design.md §14's crash-recovery table: a retried checkout
     * submission hits the durable orders.idempotency_key unique
     * constraint rather than creating a second Order — caught here and
     * resolved by returning the existing Order (same key, same payload)
     * or rejecting clearly (same key, different payload).
     */
    private function isIdempotencyKeyViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'orders_customer_id_idempotency_key_unique');
    }

    private function resolveIdempotentOrder(Customer $customer, string $idempotencyKey, ?string $payloadHash): Order
    {
        $existing = Order::where('customer_id', $customer->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            throw new IdempotencyKeyConflictException($idempotencyKey);
        }

        if ($existing->idempotency_key_payload_hash !== $payloadHash) {
            throw new IdempotencyKeyConflictException($idempotencyKey);
        }

        return $existing->load(['items', 'addresses', 'payments']);
    }
}
