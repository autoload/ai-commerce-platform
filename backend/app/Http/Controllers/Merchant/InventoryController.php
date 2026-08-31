<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\InventoryTransactionReason;
use App\Exceptions\InsufficientInventoryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\InventoryAdjustRequest;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Services\InventoryAdjustmentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * {store} is already resolved and access-verified by tenant.merchant.store
 * — no new middleware introduced, per the approved design. {variant} is
 * deliberately NOT implicitly route-bound: resolveVariant() queries it
 * scoped to $context->store->id itself, the same discipline
 * ProductController uses for {product} — a client-supplied variant id
 * belonging to a different store can never be reached.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryAdjustmentService $inventoryAdjustmentService,
    ) {}

    public function show(Request $request): InventoryResource
    {
        $context = app(TenantContext::class);
        $variant = $this->resolveVariant($request, $context);

        Gate::authorize('view', $variant);

        return new InventoryResource($this->currentOrTransientInventory($variant));
    }

    public function adjust(InventoryAdjustRequest $request): InventoryResource
    {
        $context = app(TenantContext::class);
        $variant = $this->resolveVariant($request, $context);

        Gate::authorize('adjust', $variant);

        $data = $request->validated();

        try {
            $inventory = $this->inventoryAdjustmentService->adjust(
                variant: $variant,
                delta: (int) $data['delta'],
                reason: InventoryTransactionReason::from($data['reason']),
                note: $data['note'] ?? null,
                actor: $context->user,
            );
        } catch (InsufficientInventoryException $e) {
            abort(422, $e->getMessage());
        }

        return new InventoryResource($inventory);
    }

    private function resolveVariant(Request $request, TenantContext $context): ProductVariant
    {
        $variant = ProductVariant::where('id', $request->route('variant'))
            ->where('store_id', $context->store->id)
            ->first();

        if (! $variant) {
            abort(404);
        }

        return $variant;
    }

    /**
     * A variant that has never been adjusted has no inventory row yet
     * (rows are lazily materialized on first adjustment, see
     * InventoryAdjustmentService) — return an unsaved, zero-quantity
     * representation rather than writing a row from a read request.
     */
    private function currentOrTransientInventory(ProductVariant $variant): Inventory
    {
        $inventory = Inventory::where('product_variant_id', $variant->id)->first();

        if ($inventory) {
            return $inventory;
        }

        $inventory = new Inventory;
        $inventory->product_variant_id = $variant->id;
        $inventory->quantity_on_hand = 0;

        return $inventory;
    }
}
