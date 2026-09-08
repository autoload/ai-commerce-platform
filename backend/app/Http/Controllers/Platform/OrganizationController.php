<?php

namespace App\Http\Controllers\Platform;

use App\Enums\OrganizationStatus;
use App\Exceptions\InvalidOrganizationTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\OrganizationRejectRequest;
use App\Http\Requests\Platform\OrganizationSuspendRequest;
use App\Http\Resources\Platform\OrganizationResource;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Services\OrganizationLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Platform Admin has no tenant boundary — organizations are resolved
 * globally (never scoped to any store/org context the actor belongs to),
 * matching CLAUDE.md's "platform-wide read visibility" framing. Every
 * route here sits behind auth:platform_admin only (see routes/api.php);
 * per the approved Phase 9A design, there is no OrganizationPolicy and no
 * new Platform Admin role system — PlatformAdmin has "no role column for
 * MVP" and a single flat capability set, so guard membership alone is the
 * complete authorization check.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationLifecycleService $lifecycleService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn ($case) => $case->value, OrganizationStatus::cases()))],
        ]);

        $query = Organization::query();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $organizations = $query->orderBy('created_at', 'desc')->paginate(15);

        return OrganizationResource::collection($organizations);
    }

    public function show(Request $request): OrganizationResource
    {
        return new OrganizationResource($this->resolveOrganization($request));
    }

    public function approve(Request $request): OrganizationResource
    {
        $organization = $this->resolveOrganization($request);

        try {
            $organization = $this->lifecycleService->approve($organization, $this->admin($request));
        } catch (InvalidOrganizationTransitionException $e) {
            abort(422, $e->getMessage());
        }

        return new OrganizationResource($organization);
    }

    public function reject(OrganizationRejectRequest $request): OrganizationResource
    {
        $organization = $this->resolveOrganization($request);
        $data = $request->validated();

        try {
            $organization = $this->lifecycleService->reject(
                $organization,
                $this->admin($request),
                $data['reason'],
            );
        } catch (InvalidOrganizationTransitionException $e) {
            abort(422, $e->getMessage());
        }

        return new OrganizationResource($organization);
    }

    public function suspend(OrganizationSuspendRequest $request): OrganizationResource
    {
        $organization = $this->resolveOrganization($request);
        $data = $request->validated();

        try {
            $organization = $this->lifecycleService->suspend(
                $organization,
                $this->admin($request),
                $data['reason'],
            );
        } catch (InvalidOrganizationTransitionException $e) {
            abort(422, $e->getMessage());
        }

        return new OrganizationResource($organization);
    }

    public function reactivate(Request $request): OrganizationResource
    {
        $organization = $this->resolveOrganization($request);

        try {
            $organization = $this->lifecycleService->reactivate($organization, $this->admin($request));
        } catch (InvalidOrganizationTransitionException $e) {
            abort(422, $e->getMessage());
        }

        return new OrganizationResource($organization);
    }

    private function resolveOrganization(Request $request): Organization
    {
        $organization = Organization::find($request->route('organization'));

        if (! $organization) {
            abort(404);
        }

        return $organization;
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform_admin');

        return $admin;
    }
}
