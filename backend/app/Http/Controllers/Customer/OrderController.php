<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\Order;
use App\Support\CustomerContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 7 — GET /api/customers/orders, GET /api/customers/orders/{order}.
 * Read-only; no create/update/delete (checkout and payment-retry own order
 * mutation elsewhere). {order} is deliberately NOT implicitly route-bound
 * — resolveOrder() scopes it to the authenticated customer's own id, the
 * same discipline RetryPaymentController::resolveOrder() and
 * Merchant\OrderController::resolveOrder() both already use. A cross-
 * customer, cross-store, or cross-organization order id all collapse into
 * the same 404 — this codebase's established "don't distinguish absence
 * from unavailability" convention.
 */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $context = app(CustomerContext::class);

        $orders = Order::where('store_id', $context->store->id)
            ->where('customer_id', $context->customer->id)
            ->with('latestPayment')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return CustomerOrderResource::collection($orders);
    }

    public function show(Request $request): CustomerOrderResource
    {
        $context = app(CustomerContext::class);
        $order = $this->resolveOrder($request, $context);

        $order->load(['items', 'addresses', 'latestPayment']);

        return new CustomerOrderResource($order);
    }

    private function resolveOrder(Request $request, CustomerContext $context): Order
    {
        $order = Order::where('id', $request->route('order'))
            ->where('customer_id', $context->customer->id)
            ->first();

        if (! $order) {
            abort(404);
        }

        return $order;
    }
}
