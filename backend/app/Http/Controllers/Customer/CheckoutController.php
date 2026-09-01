<?php

namespace App\Http\Controllers\Customer;

use App\Enums\CatalogStatus;
use App\Exceptions\CustomerStoreMismatchException;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\ProductVariantUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\CheckoutOrderCreationService;
use App\Services\StripePaymentIntentGateway;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\ErrorObject;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\IdempotencyException;
use Stripe\PaymentIntent;

/**
 * database-design.md §9/§13 (Database Design 2.6), STEP 3B: the checkout
 * orchestration boundary. Owns exactly the pieces CheckoutOrderCreationService
 * (STEP 3A, frozen — not modified here) deliberately does not: reading the
 * client-supplied cart/address, revalidating it against MySQL, computing a
 * provisional Stripe amount, and creating the PaymentIntent *before* the
 * atomic local write — never the reverse (§13's PaymentIntent-first
 * sequencing). No lock is ever held across the Stripe network call; the
 * only lock in this whole flow lives inside CheckoutOrderCreationService's
 * own transaction, entirely after Stripe has already resolved.
 *
 * Customer and Store come only from CustomerContext (server-resolved from
 * the authenticated token) — never from client-supplied store_id/
 * organization_id/customer_id, matching every other tenant boundary in
 * this codebase.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly StripePaymentIntentGateway $paymentIntentGateway,
        private readonly CheckoutOrderCreationService $checkoutOrderCreationService,
    ) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        $context = app(CustomerContext::class);
        $data = $request->validated();
        $idempotencyKey = $data['idempotency_key'];

        try {
            $lineItems = $this->resolveLineItems($data['items'], $context->store->id);
        } catch (ProductVariantUnavailableException $e) {
            abort(422, $e->getMessage());
        }

        $shippingAddress = $this->normalizeShippingAddress($data['shipping_address']);
        $subtotal = $this->calculateProvisionalSubtotal($lineItems);
        $payloadHash = $this->hashCheckoutPayload($context->customer->id, $lineItems, $shippingAddress);

        $paymentIntent = $this->createPaymentIntent($subtotal, $idempotencyKey, $context);

        try {
            $order = $this->checkoutOrderCreationService->createPendingOrder(
                customer: $context->customer,
                store: $context->store,
                lineItems: $lineItems,
                shippingAddress: $shippingAddress,
                stripePaymentIntentId: $paymentIntent->id,
                idempotencyKey: $idempotencyKey,
                idempotencyKeyPayloadHash: $payloadHash,
            );
        } catch (CustomerStoreMismatchException|ProductVariantUnavailableException|InsufficientInventoryException $e) {
            abort(422, $e->getMessage());
        } catch (IdempotencyKeyConflictException $e) {
            abort(409, $e->getMessage());
        }

        $this->logAmountMismatchIfAny($order, $paymentIntent);

        return $this->respond($order, $paymentIntent);
    }

    /**
     * Resolves every requested line item against MySQL, scoped directly
     * through the customer's own store — never Product::find()/
     * ProductVariant::find() followed by a separate ownership check. A
     * variant that doesn't exist, belongs to another store, or isn't
     * Active is indistinguishable from this query's perspective (same
     * "don't distinguish absence from unavailability" convention already
     * used by the public catalog controller) and rejects the whole
     * checkout before any Stripe call is made.
     *
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $items
     * @return array<int, array{variant: ProductVariant, quantity: int}>
     *
     * @throws ProductVariantUnavailableException
     */
    private function resolveLineItems(array $items, int $storeId): array
    {
        $requestedIds = array_map(fn (array $item) => (int) $item['product_variant_id'], $items);

        $variants = ProductVariant::where('store_id', $storeId)
            ->where('status', CatalogStatus::Active)
            ->whereIn('id', $requestedIds)
            ->get()
            ->keyBy('id');

        return array_map(function (array $item) use ($variants) {
            $variantId = (int) $item['product_variant_id'];
            $variant = $variants->get($variantId);

            if (! $variant) {
                // No real ProductVariant row was found for this id within
                // this store — construct a transient, unsaved instance
                // purely to carry the id into the exception's message,
                // the same "unsaved representation, never persisted"
                // pattern InventoryController already uses for a
                // never-adjusted variant's inventory row.
                $transient = new ProductVariant;
                $transient->id = $variantId;

                throw new ProductVariantUnavailableException(
                    $transient,
                    'does not exist, does not belong to this store, or is not active'
                );
            }

            return ['variant' => $variant, 'quantity' => (int) $item['quantity']];
        }, $items);
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array{recipient_name: string, line1: string, line2: ?string, city: string, state: string, postal_code: string, country: string, phone: ?string}
     */
    private function normalizeShippingAddress(array $address): array
    {
        return [
            'recipient_name' => $address['recipient_name'],
            'line1' => $address['line1'],
            'line2' => $address['line2'] ?? null,
            'city' => $address['city'],
            'state' => $address['state'],
            'postal_code' => $address['postal_code'],
            'country' => strtoupper($address['country']),
            'phone' => $address['phone'] ?? null,
        ];
    }

    /**
     * Provisional only — exists to tell Stripe an amount before the
     * PaymentIntent is created. CheckoutOrderCreationService independently
     * recomputes the authoritative total from the same live variant
     * prices inside its own transaction; that recomputation, not this
     * one, is what's actually persisted. Mirrors that service's own
     * float+round arithmetic exactly (same rounding convention).
     *
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $lineItems
     */
    private function calculateProvisionalSubtotal(array $lineItems): float
    {
        return round(array_reduce(
            $lineItems,
            fn (float $carry, array $lineItem) => $carry + ((float) $lineItem['variant']->price * $lineItem['quantity']),
            0.0
        ), 2);
    }

    /**
     * database-design.md §11: SHA-256 over a normalized, deterministic
     * representation of the checkout identity — sorted by variant id so
     * database/request ordering can never change the hash for the same
     * semantic cart, and built only from already-normalized values (never
     * client-supplied price, never the provisional Stripe amount).
     *
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $lineItems
     * @param  array<string, mixed>  $shippingAddress
     */
    private function hashCheckoutPayload(int $customerId, array $lineItems, array $shippingAddress): string
    {
        $items = array_map(fn (array $lineItem) => [
            'product_variant_id' => $lineItem['variant']->id,
            'quantity' => $lineItem['quantity'],
        ], $lineItems);

        usort($items, fn (array $a, array $b) => $a['product_variant_id'] <=> $b['product_variant_id']);

        return hash('sha256', json_encode([
            'customer_id' => $customerId,
            'items' => $items,
            'shipping_address' => $shippingAddress,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * PaymentIntent-first (§13): this happens before any local write, and
     * no DB transaction/row lock is held during this call — nothing is
     * locked yet at this point in the flow.
     *
     * IdempotencyException is caught ahead of the broader ApiErrorException
     * it extends, since PHP matches catch clauses in listed order.
     */
    private function createPaymentIntent(float $subtotal, string $idempotencyKey, CustomerContext $context): PaymentIntent
    {
        try {
            return $this->paymentIntentGateway->create([
                'amount' => (int) round($subtotal * 100),
                'currency' => 'usd',
                'payment_method_types' => ['card'],
                'metadata' => [
                    'organization_id' => (string) $context->organization->id,
                    'store_id' => (string) $context->store->id,
                    'customer_id' => (string) $context->customer->id,
                    'idempotency_key' => $idempotencyKey,
                ],
            ], $idempotencyKey);
        } catch (IdempotencyException $e) {
            if ($e->getStripeCode() === ErrorObject::CODE_IDEMPOTENCY_KEY_IN_USE) {
                abort(409, 'This checkout request is already being processed. Please try again shortly.');
            }

            abort(409, 'This idempotency key was already used for a different checkout request.');
        } catch (ApiErrorException $e) {
            report($e);
            abort(502, 'Unable to reach the payment provider. Please try again.');
        }
    }

    /**
     * Accepted, bounded MVP residual risk (STEP 3B design review, item 7):
     * the provisional Stripe amount and CheckoutOrderCreationService's
     * later, authoritative recomputation could theoretically diverge if a
     * variant's price changes in the narrow window between the two reads.
     * Logged for visibility only — deliberately no PaymentIntent::update()
     * or other Stripe mutation here.
     */
    private function logAmountMismatchIfAny(Order $order, PaymentIntent $paymentIntent): void
    {
        $orderTotalCents = (int) round(((float) $order->total) * 100);

        if ($orderTotalCents !== $paymentIntent->amount) {
            Log::warning('Checkout: provisional Stripe amount diverged from the authoritative order total.', [
                'order_id' => $order->id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_amount_cents' => $paymentIntent->amount,
                'order_total_cents' => $orderTotalCents,
            ]);
        }
    }

    private function respond(Order $order, PaymentIntent $paymentIntent): JsonResponse
    {
        return response()->json([
            'data' => new OrderResource($order),
            'payment' => [
                'client_secret' => $paymentIntent->client_secret,
                'stripe_payment_intent_id' => $paymentIntent->id,
            ],
        ], $order->wasRecentlyCreated ? 201 : 200);
    }
}
