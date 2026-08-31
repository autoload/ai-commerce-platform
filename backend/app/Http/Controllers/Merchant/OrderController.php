<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\OrderStatusUpdateRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderStatusUpdateService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * {store} is already resolved and access-verified by tenant.merchant.store
 * — no new middleware introduced, per the approved design. {order} is
 * deliberately NOT implicitly route-bound: resolveOrder() queries it scoped
 * to $context->store->id itself, the same discipline
 * ProductController/InventoryController use for {product}/{variant} — a
 * client-supplied order id belonging to a different store can never be
 * reached. Only status may be mutated (updateStatus) — no create, no
 * generic update, no delete.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderStatusUpdateService $orderStatusUpdateService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $context = app(TenantContext::class);

        Gate::authorize('viewAny', [Order::class, $context->store]);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn ($case) => $case->value, OrderStatus::cases()))],
        ]);

        $query = Order::where('store_id', $context->store->id);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate(15);

        return OrderResource::collection($orders);
    }

    public function show(Request $request): OrderResource
    {
        $context = app(TenantContext::class);
        $order = $this->resolveOrder($request, $context);

        Gate::authorize('view', $order);

        $order->load(['items', 'addresses']);

        return new OrderResource($order);
    }

    public function updateStatus(OrderStatusUpdateRequest $request): OrderResource
    {
        $context = app(TenantContext::class);
        $order = $this->resolveOrder($request, $context);

        Gate::authorize('updateStatus', $order);

        $data = $request->validated();

        try {
            $order = $this->orderStatusUpdateService->transition(
                order: $order,
                to: OrderStatus::from($data['status']),
            );
        } catch (InvalidOrderTransitionException $e) {
            abort(422, $e->getMessage());
        }

        return new OrderResource($order);
    }

    private function resolveOrder(Request $request, TenantContext $context): Order
    {
        $order = Order::where('id', $request->route('order'))
            ->where('store_id', $context->store->id)
            ->first();

        if (! $order) {
            abort(404);
        }

        return $order;
    }
}
