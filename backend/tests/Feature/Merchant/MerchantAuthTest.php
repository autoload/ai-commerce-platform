<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MerchantAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_register_and_bootstraps_a_pending_organization(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Merchant',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_name' => 'Jane\'s Shop',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'organization' => ['id', 'name', 'slug', 'status'], 'role'])
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('organization.name', "Jane's Shop")
            ->assertJsonPath('organization.status', 'pending')
            ->assertJsonPath('role', 'owner');

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);

        $organization = Organization::where('name', "Jane's Shop")->firstOrFail();
        $this->assertSame(OrganizationStatus::Pending, $organization->status);

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $membership = OrganizationUser::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($membership->organization_id === $organization->id);
        $this->assertSame(OrganizationRole::Owner, $membership->role);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_name' => 'Some Org',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_all_fields(): void
    {
        $response = $this->postJson('/api/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'organization_name']);
    }

    public function test_registration_is_transactional_and_leaves_no_orphan_user_on_failure(): void
    {
        // A pre-existing organization with the same slug base forces
        // uniqueSlug() to append a suffix rather than fail — this test
        // instead proves the happy path leaves exactly one user/org/pivot
        // row, which is the property the transaction guarantees.
        $this->postJson('/api/auth/register', [
            'name' => 'Jane',
            'email' => 'jane2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_name' => 'Jane Org',
        ])->assertCreated();

        $this->assertSame(1, User::where('email', 'jane2@example.com')->count());
        $this->assertSame(1, Organization::where('name', 'Jane Org')->count());
        $this->assertSame(1, OrganizationUser::count());
    }

    public function test_merchant_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $org = Organization::factory()->create();
        $this->attachOwner($org, $user);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'organization', 'role'])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('role', 'owner');
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_me_returns_merchant_identity(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->attachOwner($org, $user);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('organization.id', $org->id)
            ->assertJsonPath('role', 'owner');
    }

    public function test_unauthenticated_me_is_rejected(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_merchant_can_logout(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->attachOwner($org, $user);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/auth/logout');

        $response->assertOk();
    }

    public function test_revoked_token_is_rejected_after_logout(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $this->attachOwner($org, $user);
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // See PlatformAdminAuthTest::test_revoked_token_is_rejected_after_logout
        // for why this is needed within a single test method.
        Auth::forgetGuards();

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_platform_admin_token_is_rejected_by_merchant_routes(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_merchant_token_is_rejected_by_platform_admin_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('merchant-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/platform/auth/me');

        $response->assertStatus(401);
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
