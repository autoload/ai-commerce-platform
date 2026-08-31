<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\OrganizationStatus;
use App\Enums\StoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CatalogProductResource;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public, unauthenticated catalog browsing — no guard, no Policy, no
 * TenantContext/CustomerContext. {store} is resolved here (never via
 * Product::find($id) followed by an ownership check) so every product
 * query is scoped through Store -> Products at the query itself, per the
 * approved Phase 1 design. An inactive store, a store whose organization
 * isn't active, or a nonexistent store all resolve to the same 404 — there
 * is no tenant identity here for a 403/404 distinction to protect, unlike
 * the merchant-side store context.
 *
 * Read-only: never touches inventory, orders, or payments.
 */
class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $store = $this->resolveActiveStore($request);

        $products = $store->products()
            ->where('status', CatalogStatus::Active)
            ->with($this->eagerLoads())
            ->orderBy('name')
            ->paginate(15);

        return CatalogProductResource::collection($products);
    }

    public function show(Request $request): CatalogProductResource
    {
        $store = $this->resolveActiveStore($request);

        $product = $store->products()
            ->where('id', $request->route('product'))
            ->where('status', CatalogStatus::Active)
            ->with($this->eagerLoads())
            ->first();

        if (! $product) {
            abort(404);
        }

        return new CatalogProductResource($product);
    }

    private function resolveActiveStore(Request $request): Store
    {
        $store = Store::where('id', $request->route('store'))->first();

        if (! $store || $store->status !== StoreStatus::Active) {
            abort(404);
        }

        if ($store->organization->status !== OrganizationStatus::Active) {
            abort(404);
        }

        return $store;
    }

    /**
     * @return array<string, mixed>
     */
    private function eagerLoads(): array
    {
        return [
            'category',
            'images' => fn ($query) => $query->orderBy('sort_order'),
            'options' => fn ($query) => $query->orderBy('sort_order'),
            'options.values' => fn ($query) => $query->orderBy('sort_order'),
            'variants' => fn ($query) => $query->where('status', CatalogStatus::Active)->orderBy('sort_order'),
            'variants.optionValues.option',
        ];
    }
}
