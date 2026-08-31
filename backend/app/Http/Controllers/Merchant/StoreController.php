<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreCreateRequest;
use App\Http\Requests\Merchant\StoreUpdateRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    /**
     * Owner sees every store in the organization; Store Admin/Staff see
     * only stores they have a store_user row for.
     */
    public function index(): AnonymousResourceCollection
    {
        $context = app(TenantContext::class);

        Gate::authorize('viewAny', [Store::class, $context->organization]);

        $query = Store::where('organization_id', $context->organization->id);

        if ($context->role !== OrganizationRole::Owner) {
            $query->whereHas('users', fn ($q) => $q->where('user_id', $context->user->id));
        }

        return StoreResource::collection($query->orderBy('name')->paginate(15));
    }

    public function store(StoreCreateRequest $request): JsonResponse
    {
        $context = app(TenantContext::class);

        Gate::authorize('create', [Store::class, $context->organization]);

        $data = $request->validated();

        $store = new Store;
        $store->organization_id = $context->organization->id;
        $store->name = $data['name'];
        $store->slug = $this->uniqueSlug($context->organization->id, $data['name']);
        $store->save();
        // Re-read so the DB-level default (status: active) is reflected on
        // the in-memory model rather than left unset.
        $store->refresh();

        return (new StoreResource($store))->response()->setStatusCode(201);
    }

    /** {store} already resolved and access-verified by tenant.merchant.store. */
    public function show(): StoreResource
    {
        $context = app(TenantContext::class);

        // The middleware only established coarse "can this user reach this
        // store" access; this is the fine-grained action-level check,
        // deliberately not skipped just because the middleware already ran.
        Gate::authorize('view', $context->store);

        return new StoreResource($context->store);
    }

    public function update(StoreUpdateRequest $request): StoreResource
    {
        $context = app(TenantContext::class);
        $store = $context->store;

        Gate::authorize('update', $store);

        $data = $request->validated();

        // Direct property assignment, not mass assignment: `status` is
        // deliberately excluded from Store's fillable list (workflow/status
        // columns are never mass-assignable, per the Phase 3 convention).
        if (array_key_exists('name', $data)) {
            $store->name = $data['name'];
        }
        if (array_key_exists('status', $data)) {
            $store->status = $data['status'];
        }
        $store->save();

        return new StoreResource($store);
    }

    public function destroy(): JsonResponse
    {
        $context = app(TenantContext::class);
        $store = $context->store;

        Gate::authorize('delete', $store);

        // No dependent-resource (Products/Orders/Customers/...) blocking
        // check yet, per database-design.md's "Cascading soft-deletes"
        // rule — those resources don't exist in Block 3. Add the guard
        // here (before delete()) once they do.
        $store->delete();

        return response()->json([
            'message' => 'Store deleted.',
        ]);
    }

    private function uniqueSlug(int $organizationId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Store::where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
