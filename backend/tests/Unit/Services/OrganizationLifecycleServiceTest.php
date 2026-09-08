<?php

namespace Tests\Unit\Services;

use App\Enums\OrganizationStatus;
use App\Exceptions\InvalidOrganizationTransitionException;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Services\OrganizationLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 9A — OrganizationLifecycleService, the single locked mutation path
 * for Platform-Admin-triggered organization status changes. Mirrors
 * OrderStatusUpdateService's proven test shape (a SELECT ... FOR UPDATE
 * proof, happy-path-per-transition, invalid-transition rejection), plus
 * the Reactivate-specific audit-column assertions the approved design
 * requires.
 */
class OrganizationLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OrganizationLifecycleService
    {
        return app(OrganizationLifecycleService::class);
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
        $org->suspended_at = now()->subDay();
        $org->suspended_by_platform_admin_id = PlatformAdmin::factory()->create()->id;
        $org->status_reason = 'Original suspension reason.';
        $org->save();

        return $org;
    }

    public function test_approve_transitions_pending_to_active(): void
    {
        $org = $this->pendingOrganization();
        $admin = PlatformAdmin::factory()->create();

        $result = $this->service()->approve($org, $admin);

        $this->assertSame(OrganizationStatus::Active, $result->status);
        $this->assertNotNull($result->approved_at);
        $this->assertSame($admin->id, $result->approved_by_platform_admin_id);
    }

    public function test_reject_transitions_pending_to_rejected_with_reason(): void
    {
        $org = $this->pendingOrganization();
        $admin = PlatformAdmin::factory()->create();

        $result = $this->service()->reject($org, $admin, 'Incomplete business registration.');

        $this->assertSame(OrganizationStatus::Rejected, $result->status);
        $this->assertNotNull($result->rejected_at);
        $this->assertSame($admin->id, $result->rejected_by_platform_admin_id);
        $this->assertSame('Incomplete business registration.', $result->status_reason);
    }

    public function test_suspend_transitions_active_to_suspended_with_reason(): void
    {
        $org = $this->activeOrganization();
        $admin = PlatformAdmin::factory()->create();

        $result = $this->service()->suspend($org, $admin, 'Suspected policy violation.');

        $this->assertSame(OrganizationStatus::Suspended, $result->status);
        $this->assertNotNull($result->suspended_at);
        $this->assertSame($admin->id, $result->suspended_by_platform_admin_id);
        $this->assertSame('Suspected policy violation.', $result->status_reason);
    }

    /**
     * The approved-design-mandated behavior: Reactivate reuses the
     * approved_at/approved_by_platform_admin_id pair (no new column) and
     * deliberately leaves the prior suspension's audit trail untouched.
     */
    public function test_reactivate_transitions_suspended_to_active_and_preserves_suspension_audit_trail(): void
    {
        $org = $this->suspendedOrganization();
        $originalSuspendedAt = $org->suspended_at;
        $originalSuspendedBy = $org->suspended_by_platform_admin_id;
        $originalReason = $org->status_reason;
        $admin = PlatformAdmin::factory()->create();

        $result = $this->service()->reactivate($org, $admin);

        $this->assertSame(OrganizationStatus::Active, $result->status);
        $this->assertNotNull($result->approved_at);
        $this->assertSame($admin->id, $result->approved_by_platform_admin_id);

        $this->assertTrue($result->suspended_at->equalTo($originalSuspendedAt));
        $this->assertSame($originalSuspendedBy, $result->suspended_by_platform_admin_id);
        $this->assertSame($originalReason, $result->status_reason);
    }

    public function test_approve_rejects_an_organization_that_is_not_pending(): void
    {
        $org = $this->activeOrganization();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(InvalidOrganizationTransitionException::class);

        $this->service()->approve($org, $admin);
    }

    public function test_reactivate_rejects_an_organization_that_is_not_suspended(): void
    {
        $org = $this->pendingOrganization();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(InvalidOrganizationTransitionException::class);

        $this->service()->reactivate($org, $admin);
    }

    public function test_reject_rejects_an_organization_that_is_not_pending(): void
    {
        $org = $this->activeOrganization();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(InvalidOrganizationTransitionException::class);

        $this->service()->reject($org, $admin, 'Some reason.');
    }

    public function test_suspend_rejects_an_organization_that_is_not_active(): void
    {
        $org = $this->pendingOrganization();
        $admin = PlatformAdmin::factory()->create();

        $this->expectException(InvalidOrganizationTransitionException::class);

        $this->service()->suspend($org, $admin, 'Some reason.');
    }

    /**
     * Forces a genuine failure *inside* the locked transaction, after the
     * transition is validated but during save, to prove DB::transaction()
     * rolls back — not just that validation can reject a request
     * beforehand.
     */
    public function test_forced_failure_during_transition_rolls_back(): void
    {
        $org = $this->pendingOrganization();
        $admin = PlatformAdmin::factory()->create();

        Organization::saving(function () {
            throw new \RuntimeException('Simulated failure to verify organization lifecycle rollback.');
        });

        try {
            $this->service()->approve($org, $admin);
            $this->fail('Expected the forced saving() failure to propagate.');
        } catch (\RuntimeException $e) {
            // Expected — asserting DB state below is the actual proof.
        }

        $fresh = Organization::find($org->id);
        $this->assertSame(OrganizationStatus::Pending, $fresh->status);
        $this->assertNull($fresh->approved_at);
    }

    public function test_lifecycle_transition_uses_row_locking(): void
    {
        $org = $this->pendingOrganization();
        $admin = PlatformAdmin::factory()->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service()->approve($org, $admin);

        $lockingQueryFound = collect($queries)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'organizations') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertTrue($lockingQueryFound, 'Expected a SELECT ... FOR UPDATE query against organizations during the lifecycle transition.');
    }
}
