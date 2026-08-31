<?php

namespace App\Http\Controllers\Merchant;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\MerchantLoginRequest;
use App\Http\Requests\Merchant\MerchantRegisterRequest;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Support\TenantAccess;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantAuthController extends Controller
{
    /**
     * Registers the merchant User and, in the same transaction, creates
     * their initial Organization (status: pending) and the owning
     * organization_user row. A partial registration (User without an
     * Organization, or vice versa) must never be possible.
     */
    public function register(MerchantRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $organization, $role] = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $organization = Organization::create([
                'name' => $data['organization_name'],
                'slug' => $this->uniqueSlug($data['organization_name']),
            ]);
            // Re-read so the DB-level default (status: pending) is reflected
            // on the in-memory model rather than left unset.
            $organization->refresh();

            $membership = new OrganizationUser;
            $membership->organization_id = $organization->id;
            $membership->user_id = $user->id;
            $membership->role = OrganizationRole::Owner;
            $membership->save();

            return [$user, $organization, $membership->role];
        });

        $token = $user->createToken('merchant-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->presentUser($user),
            'organization' => $this->presentOrganization($organization),
            'role' => $role->value,
        ], 201);
    }

    public function login(MerchantLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('merchant-session')->plainTextToken;
        $membership = TenantAccess::membershipFor($user);

        return response()->json([
            'token' => $token,
            'user' => $this->presentUser($user),
            'organization' => $membership ? $this->presentOrganization($membership->organization) : null,
            'role' => $membership?->role->value,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('merchant')->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);

        return response()->json([
            'user' => $this->presentUser($context->user),
            'organization' => $this->presentOrganization($context->organization),
            'role' => $context->role->value,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrganization(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status->value,
        ];
    }
}
