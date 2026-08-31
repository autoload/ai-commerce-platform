<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\ProductCreateRequest;
use App\Http\Requests\Merchant\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * {store} is already resolved and access-verified by tenant.merchant.store
 * (no new middleware introduced for Product, per the approved design —
 * Product is directly store-scoped, so the existing store context is
 * sufficient). {product} is deliberately NOT implicitly route-bound, for
 * the same reason StoreController doesn't implicitly bind {store}: this
 * controller resolves it itself, scoped to $context->store, so a
 * client-supplied product id belonging to a different store can never be
 * reached.
 */
class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $context = app(TenantContext::class);

        Gate::authorize('viewAny', [Product::class, $context->store]);

        $products = Product::where('store_id', $context->store->id)
            ->with('variants')
            ->orderBy('name')
            ->paginate(15);

        return ProductResource::collection($products);
    }

    /**
     * Product + its single default ProductVariant are created atomically —
     * if either insert fails, neither remains. option_signature is fixed
     * to '' (no options exist in this MVP); no VariantOptionService.
     */
    public function store(ProductCreateRequest $request): JsonResponse
    {
        $context = app(TenantContext::class);

        Gate::authorize('create', [Product::class, $context->store]);

        $data = $request->validated();
        $status = $data['status'] ?? CatalogStatus::Draft->value;

        $product = DB::transaction(function () use ($data, $status, $context) {
            $product = new Product;
            $product->organization_id = $context->organization->id;
            $product->store_id = $context->store->id;
            $product->category_id = $data['category_id'] ?? null;
            $product->name = $data['name'];
            $product->slug = $this->uniqueSlug($context->store->id, $data['name']);
            $product->description = $data['description'] ?? null;
            $product->status = $status;
            $product->save();

            $variant = new ProductVariant;
            $variant->organization_id = $context->organization->id;
            $variant->store_id = $context->store->id;
            $variant->product_id = $product->id;
            $variant->sku = $data['sku'];
            $variant->price = $data['price'];
            $variant->compare_at_price = $data['compare_at_price'] ?? null;
            $variant->status = $status;
            $variant->option_signature = '';
            $variant->save();

            return $product;
        });

        $product->refresh();
        $product->load('variants');

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Request $request): ProductResource
    {
        $context = app(TenantContext::class);
        $product = $this->resolveProduct($request, $context);

        Gate::authorize('view', $product);

        $product->load('variants');

        return new ProductResource($product);
    }

    /** Updates the Product and its single default variant together — the only variant that exists in this MVP. */
    public function update(ProductUpdateRequest $request): ProductResource
    {
        $context = app(TenantContext::class);
        $product = $this->resolveProduct($request, $context);

        Gate::authorize('update', $product);

        $data = $request->validated();

        DB::transaction(function () use ($product, $data) {
            if (array_key_exists('name', $data)) {
                $product->name = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $product->description = $data['description'];
            }
            if (array_key_exists('category_id', $data)) {
                $product->category_id = $data['category_id'];
            }
            if (array_key_exists('status', $data)) {
                $product->status = $data['status'];
            }
            $product->save();

            $variant = $product->variants()->first();

            if ($variant) {
                if (array_key_exists('sku', $data)) {
                    $variant->sku = $data['sku'];
                }
                if (array_key_exists('price', $data)) {
                    $variant->price = $data['price'];
                }
                if (array_key_exists('compare_at_price', $data)) {
                    $variant->compare_at_price = $data['compare_at_price'];
                }
                if (array_key_exists('status', $data)) {
                    $variant->status = $data['status'];
                }
                $variant->save();
            }
        });

        $product->refresh();
        $product->load('variants');

        return new ProductResource($product);
    }

    public function destroy(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        $product = $this->resolveProduct($request, $context);

        Gate::authorize('delete', $product);

        // The default variant is deliberately NOT cascaded/soft-deleted —
        // matches Database Design 2.3's documented rule that a product
        // soft-delete is never blocked by, and never cascades to, its
        // variants: they remain exactly as they were (order-history/
        // inventory-ledger integrity), only reachability through the
        // product changes.
        $product->delete();

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }

    private function resolveProduct(Request $request, TenantContext $context): Product
    {
        $product = Product::where('id', $request->route('product'))
            ->where('store_id', $context->store->id)
            ->first();

        if (! $product) {
            abort(404);
        }

        return $product;
    }

    private function uniqueSlug(int $storeId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Product::where('store_id', $storeId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
