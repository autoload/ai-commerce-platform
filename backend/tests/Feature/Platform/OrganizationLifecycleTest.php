<?php

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9A — feature-level coverage for the Platform Admin organization
 * lifecycle: list/detail, the four approved transitions end-to-end over
 * HTTP, validation, invalid-transition rejection, and guard isolation
 * (merchant/customer tokens must never reach these routes). Unit-level
 * whitelist/service behavior is covered by
 * OrganizationLifecycleTransitionsTest and OrganizationLifecycleServiceTest
 * — this file proves the HTTP layer wires them correctly, matching
 * PlatformAdminAuthTest's existing guard-isolation-testing convention.
 */
class OrganizationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return PlatformAdmin::factory()->create()->createToken('t')->plainTextToken;
    }

    private function pendingOrganization(): Organization
    {
        return Organization::factory()->create();
    }

    private function activeOrganization(): Organization
    {
        $org = Organization::factory()->create();
        $org->status = OrganizationStatus::Active;
        $org->save();

        return $org;
    }

    private function suspendedOrganization(): Organization
    {
        $org = $this->activeOrganization();
        $org->status = OrganizationStatus::Suspended;
        $org->suspended_at = now();
        $org->suspended_by_platform_admin_id = PlatformAdmin::factory()->create()->id;
        $org->status_reason = 'Prior suspension.';
        $org->save();

        return $org;
    }

    // ---- List / detail ----------------------------------------------

    public function test_platform_admin_can_list_organizations(): void
    {
        $token = $this->adminToken();
        $this->pendingOrganization();
        $this->activeOrganization();

        $response = $this->withToken($token)->getJson('/api/platform/organizations');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'status']], 'meta']);
    }

    public function test_platform_admin_can_filter_organizations_by_status(): void
    {
        $token = $this->adminToken();
        $this->pendingOrganization();
        $active = $this->activeOrganization();

        $response = $this->withToken($token)->getJson('/api/platform/organizations?status=active');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $token = $this->adminToken();

        $response = $this->withToken($token)->getJson('/api/platform/organizations?status=not-a-real-status');

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_platform_admin_can_view_organization_detail(): void
    {
        $token = $this->adminToken();
        $org = $this->pendingOrganization();

        $response = $this->withToken($token)->getJson("/api/platform/organizations/{$org->id}");

        $response->assertOk()->assertJsonPath('data.id', $org->id);
    }

    public function test_nonexistent_organization_returns_404(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/platform/organizations/999999')->assertStatus(404);
    }

    // ---- Approve -------------------------------------------------------

    public function test_approve_transitions_pending_organization_to_active(): void
    {
        $token = $this->adminToken();
        $org = $this->pendingOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/approve");

        $response->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'active']);
    }

    public function test_approve_on_a_non_pending_organization_is_rejected(): void
    {
        $token = $this->adminToken();
        $org = $this->activeOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/approve");

        $response->assertStatus(422);
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'active']);
    }

    // ---- Reject ----------------------------------------------------------

    public function test_reject_transitions_pending_organization_to_rejected_with_reason(): void
    {
        $token = $this->adminToken();
        $org = $this->pendingOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reject", [
            'reason' => 'Business registration could not be verified.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_reason', 'Business registration could not be verified.');
    }

    public function test_reject_without_reason_returns_422(): void
    {
        $token = $this->adminToken();
        $org = $this->pendingOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reject", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'pending']);
    }

    public function test_reject_on_a_non_pending_organization_is_rejected(): void
    {
        $token = $this->adminToken();
        $org = $this->activeOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reject", [
            'reason' => 'Some reason.',
        ]);

        $response->assertStatus(422);
    }

    // ---- Suspend -----------------------------------------------------

    public function test_suspend_transitions_active_organization_to_suspended_with_reason(): void
    {
        $token = $this->adminToken();
        $org = $this->activeOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/suspend", [
            'reason' => 'Suspected policy violation.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', 'Suspected policy violation.');
    }

    public function test_suspend_without_reason_returns_422(): void
    {
        $token = $this->adminToken();
        $org = $this->activeOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/suspend", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'active']);
    }

    public function test_suspend_on_a_non_active_organization_is_rejected(): void
    {
        $token = $this->adminToken();
        $org = $this->pendingOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/suspend", [
            'reason' => 'Some reason.',
        ]);

        $response->assertStatus(422);
    }

    // ---- Reactivate --------------------------------------------------

    public function test_reactivate_transitions_suspended_organization_to_active(): void
    {
        $token = $this->adminToken();
        $org = $this->suspendedOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reactivate");

        $response->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'active']);
    }

    public function test_reactivate_on_a_non_suspended_organization_is_rejected(): void
    {
        $token = $this->adminToken();
        $org = $this->pendingOrganization();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reactivate");

        $response->assertStatus(422);
    }

    public function test_rejected_organization_cannot_be_reactivated(): void
    {
        $token = $this->adminToken();
        $org = $this->pendingOrganization();
        $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reject", [
            'reason' => 'Not eligible.',
        ])->assertOk();

        $response = $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reactivate");

        $response->assertStatus(422);
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'status' => 'rejected']);
    }

    // ---- Guard isolation -----------------------------------------------

    public function test_unauthenticated_request_is_rejected_on_every_organization_route(): void
    {
        $org = $this->pendingOrganization();

        $this->getJson('/api/platform/organizations')->assertStatus(401);
        $this->getJson("/api/platform/organizations/{$org->id}")->assertStatus(401);
        $this->postJson("/api/platform/organizations/{$org->id}/approve")->assertStatus(401);
        $this->postJson("/api/platform/organizations/{$org->id}/reject", ['reason' => 'x'])->assertStatus(401);
        $this->postJson("/api/platform/organizations/{$org->id}/suspend", ['reason' => 'x'])->assertStatus(401);
        $this->postJson("/api/platform/organizations/{$org->id}/reactivate")->assertStatus(401);
    }

    public function test_merchant_token_is_rejected_on_every_organization_route(): void
    {
        $org = $this->pendingOrganization();
        $token = User::factory()->create()->createToken('merchant-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/platform/organizations')->assertStatus(401);
        $this->withToken($token)->getJson("/api/platform/organizations/{$org->id}")->assertStatus(401);
        $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/approve")->assertStatus(401);
        $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/reactivate")->assertStatus(401);
    }

    public function test_customer_token_is_rejected_on_every_organization_route(): void
    {
        $store = Store::factory()->forOrganization($this->activeOrganization())->create();
        $customer = Customer::factory()->forStore($store)->create();
        $token = $customer->createToken('customer-token')->plainTextToken;
        $org = $this->pendingOrganization();

        $this->withToken($token)->getJson('/api/platform/organizations')->assertStatus(401);
        $this->withToken($token)->getJson("/api/platform/organizations/{$org->id}")->assertStatus(401);
        $this->withToken($token)->postJson("/api/platform/organizations/{$org->id}/approve")->assertStatus(401);
    }
}
