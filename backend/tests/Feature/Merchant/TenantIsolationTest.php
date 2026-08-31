<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_cannot_access_another_merchants_organization_context(): void
    {
        $orgA = Organization::factory()->create();
        $userA = User::factory()->create();
        $this->attachOwner($orgA, $userA);
        $tokenA = $userA->createToken('a')->plainTextToken;

        $orgB = Organization::factory()->create();
        $userB = User::factory()->create();
        $this->attachOwner($orgB, $userB);
        $tokenB = $userB->createToken('b')->plainTextToken;

        $responseA = $this->withToken($tokenA)->getJson('/api/auth/me');

        // Two simulated requests in one test method share the same booted
        // Application, and RequestGuard caches its resolved user for that
        // Application's lifetime — without this, the second call below
        // would see the first request's cached userA instead of
        // re-authenticating as userB. Real traffic boots a fresh
        // Application per request, so this cannot happen outside tests.
        // See PlatformAdminAuthTest::test_revoked_token_is_rejected_after_logout.
        Auth::forgetGuards();

        $responseB = $this->withToken($tokenB)->getJson('/api/auth/me');

        $responseA->assertOk()->assertJsonPath('organization.id', $orgA->id);
        $responseB->assertOk()->assertJsonPath('organization.id', $orgB->id);
        $this->assertNotSame($orgA->id, $orgB->id);
    }

    public function test_client_supplied_organization_id_cannot_override_the_resolved_tenant(): void
    {
        $orgA = Organization::factory()->create();
        $userA = User::factory()->create();
        $this->attachOwner($orgA, $userA);
        $tokenA = $userA->createToken('a')->plainTextToken;

        $orgB = Organization::factory()->create();

        // Attempt to spoof the tenant via a client-supplied organization_id,
        // both as a query param and as a header — TenantContext must ignore
        // both and resolve strictly from the authenticated user's own
        // organization_user row.
        $response = $this->withToken($tokenA)
            ->withHeaders(['X-Organization-Id' => (string) $orgB->id])
            ->getJson('/api/auth/me?organization_id='.$orgB->id);

        $response->assertOk()->assertJsonPath('organization.id', $orgA->id);
    }

    public function test_merchant_token_cannot_cross_into_platform_admin_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('merchant')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/platform/auth/me');

        $response->assertStatus(401);
    }

    public function test_platform_admin_token_cannot_cross_into_merchant_routes(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_organization_owner_role_is_resolved_correctly(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attachOwner($org, $user);
        $token = $user->createToken('a')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk()->assertJsonPath('role', 'owner');
    }

    public function test_tenant_context_contains_the_expected_organization_user_and_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $membership = $this->attachOwner($org, $user);
        $token = $user->createToken('a')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('organization.id', $org->id)
            ->assertJsonPath('role', $membership->role->value);
    }

    private function attachOwner(Organization $org, User $user): OrganizationUser
    {
        $membership = new OrganizationUser;
        $membership->organization_id = $org->id;
        $membership->user_id = $user->id;
        $membership->role = OrganizationRole::Owner;
        $membership->save();

        return $membership;
    }
}
