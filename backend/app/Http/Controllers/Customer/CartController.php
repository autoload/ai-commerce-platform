<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddCartItemRequest;
use App\Http\Requests\Customer\MergeCartRequest;
use App\Http\Requests\Customer\UpdateCartItemRequest;
use App\Services\CartService;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase 8B revision — the authenticated-customer cart API. CartContext
 * comes only from the container (bound by the existing ResolveCustomerContext
 * middleware, same as CheckoutController/OrderController/RetryPaymentController)
 * — never from a client-supplied customer_id/organization_id/store_id, so
 * the Redis key a request can ever touch is structurally limited to the
 * authenticated customer's own cart. The controller never talks to Redis
 * itself; CartService owns every mutation/read.
 *
 * {variant} is a Redis hash field, not an Eloquent-bound model — there is
 * no "does this variant belong to this store" check to perform here the
 * way {product}/{order} resolution does elsewhere, since an unrecognized
 * or wrong-store variant id simply never hydrates into a real cart line
 * (see CartService::hydrate()) and mutating a field for it is harmless.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
    ) {}

    public function show(): JsonResponse
    {
        $context = app(CustomerContext::class);

        return response()->json(['data' => $this->cartService->getCart($context)]);
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $context = app(CustomerContext::class);
        $data = $request->validated();

        $cart = $this->cartService->addItem($context, (int) $data['product_variant_id'], (int) $data['quantity']);

        return response()->json(['data' => $cart]);
    }

    public function updateItem(UpdateCartItemRequest $request): JsonResponse
    {
        $context = app(CustomerContext::class);
        $variantId = (int) $request->route('variant');
        $quantity = (int) $request->validated()['quantity'];

        $cart = $this->cartService->setItemQuantity($context, $variantId, $quantity);

        return response()->json(['data' => $cart]);
    }

    public function removeItem(Request $request): JsonResponse
    {
        $context = app(CustomerContext::class);
        $variantId = (int) $request->route('variant');

        $cart = $this->cartService->removeItem($context, $variantId);

        return response()->json(['data' => $cart]);
    }

    public function clear(): Response
    {
        $context = app(CustomerContext::class);
        $this->cartService->clearCart($context);

        return response()->noContent();
    }

    public function merge(MergeCartRequest $request): JsonResponse
    {
        $context = app(CustomerContext::class);
        $items = $request->validated()['items'] ?? [];

        $cart = $this->cartService->mergeGuestCart($context, $items);

        return response()->json(['data' => $cart]);
    }
}
