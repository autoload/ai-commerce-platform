<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_login_with_valid_credentials(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'platform_admin' => ['id', 'name', 'email']])
            ->assertJsonPath('platform_admin.id', $admin->id);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/platform/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_valid_platform_admin_token_can_access_protected_route(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/platform/auth/me');

        $response->assertOk()
            ->assertJsonPath('platform_admin.id', $admin->id);
    }

    public function test_merchant_token_is_rejected_by_platform_admin_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('merchant-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/platform/auth/me');

        $response->assertStatus(401);
    }

    public function test_platform_admin_token_is_rejected_by_merchant_routes(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_platform_admin_can_logout(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/platform/auth/logout');

        $response->assertOk();
    }

    public function test_revoked_token_is_rejected_after_logout(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('test-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/platform/auth/logout')->assertOk();

        // Laravel's RequestGuard caches its resolved user for the lifetime
        // of the booted Application, so within a single test method a second
        // simulated request would otherwise see the guard's stale cache
        // instead of re-authenticating — this cannot happen on real traffic,
        // where every request boots a fresh Application. Forgetting the
        // cached guard here simulates the fresh Application a real second
        // request would get.
        Auth::forgetGuards();

        $response = $this->withToken($token)->getJson('/api/platform/auth/me');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/platform/auth/me');

        $response->assertStatus(401);
    }
}
