<?php

namespace App\Http\Controllers\Merchant;

use App\Exceptions\CategoryHasActiveDependentsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\CategoryCreateRequest;
use App\Http\Requests\Merchant\CategoryUpdateRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * {store} is already resolved and access-verified by tenant.merchant.store
 * — no new middleware introduced, per the approved design (Category is
 * directly store-scoped, same as Product). {category} is deliberately NOT
 * implicitly route-bound: resolveCategory() queries it scoped to
 * $context->store->id itself, the same discipline
 * ProductController/OrderController use for {product}/{order} — a
 * client-supplied category id belonging to a different store can never be
 * reached.
 */
class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $context = app(TenantContext::class);

        Gate::authorize('viewAny', [Category::class, $context->store]);

        $categories = Category::where('store_id', $context->store->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(15);

        return CategoryResource::collection($categories);
    }

    public function store(CategoryCreateRequest $request): JsonResponse
    {
        $context = app(TenantContext::class);

        Gate::authorize('create', [Category::class, $context->store]);

        $data = $request->validated();

        $category = new Category;
        $category->organization_id = $context->organization->id;
        $category->store_id = $context->store->id;
        $category->name = $data['name'];
        $category->slug = $this->uniqueSlug($context->store->id, $data['name']);
        $category->description = $data['description'] ?? null;
        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $category->sort_order = $data['sort_order'];
        }
        $category->save();
        // Re-read so the DB-level default (sort_order: 0, when not
        // explicitly provided) is reflected on the in-memory model rather
        // than left unset.
        $category->refresh();

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Request $request): CategoryResource
    {
        $context = app(TenantContext::class);
        $category = $this->resolveCategory($request, $context);

        Gate::authorize('view', $category);

        return new CategoryResource($category);
    }

    public function update(CategoryUpdateRequest $request): CategoryResource
    {
        $context = app(TenantContext::class);
        $category = $this->resolveCategory($request, $context);

        Gate::authorize('update', $category);

        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $category->description = $data['description'];
        }
        if (array_key_exists('sort_order', $data)) {
            $category->sort_order = $data['sort_order'] ?? 0;
        }
        $category->save();

        return new CategoryResource($category);
    }

    public function destroy(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        $category = $this->resolveCategory($request, $context);

        Gate::authorize('delete', $category);

        try {
            $this->assertNoActiveDependents($category);
        } catch (CategoryHasActiveDependentsException $e) {
            abort(422, $e->getMessage());
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted.',
        ]);
    }

    /**
     * products.category_id's nullOnDelete() FK action never fires on
     * Eloquent's SoftDeletes (an UPDATE, not a DELETE) — this guard is what
     * actually protects against orphaning a still-referenced category.
     * Product::where(...)->exists() already excludes soft-deleted products
     * via Eloquent's default global scope, so a category whose only
     * referencing products are themselves soft-deleted is never blocked.
     *
     * @throws CategoryHasActiveDependentsException
     */
    private function assertNoActiveDependents(Category $category): void
    {
        $hasReferencingProduct = Product::where('category_id', $category->id)->exists();

        if ($hasReferencingProduct) {
            throw CategoryHasActiveDependentsException::forActiveProducts();
        }
    }

    private function resolveCategory(Request $request, TenantContext $context): Category
    {
        $category = Category::where('id', $request->route('category'))
            ->where('store_id', $context->store->id)
            ->first();

        if (! $category) {
            abort(404);
        }

        return $category;
    }

    private function uniqueSlug(int $storeId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Category::where('store_id', $storeId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
