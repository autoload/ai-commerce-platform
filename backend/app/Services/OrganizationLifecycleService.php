<?php

namespace App\Services;

use App\Enums\OrganizationStatus;
use App\Exceptions\InvalidOrganizationTransitionException;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Support\OrganizationLifecycleTransitions;
use Illuminate\Support\Facades\DB;

/**
 * The single mutation path for Platform-Admin-triggered organization
 * lifecycle changes. Mirrors OrderStatusUpdateService's proven shape: lock
 * the row inside a transaction, re-validate against the freshly-read
 * current state (never a possibly-stale value the caller saw), apply,
 * commit — the loser of any race (two admins acting on the same
 * organization concurrently) is rejected rather than silently overwriting.
 *
 * Unlike the Order state machine (where every target status has exactly
 * one valid originating status), Approve and Reactivate both target
 * `active` from two different originating statuses (`pending` and
 * `suspended` respectively) — OrganizationLifecycleTransitions' bare
 * (from, to) whitelist alone can't distinguish "this call must be an
 * Approve" from "this call must be a Reactivate". Each public method here
 * therefore pins its own expected starting status explicitly, in addition
 * to consulting the whitelist — so calling approve() on a suspended
 * organization (or reactivate() on a pending one) is rejected even though
 * `suspended -> active` and `pending -> active` are each independently
 * valid edges.
 *
 * Reactivate reuses the existing approved_at/approved_by_platform_admin_id
 * pair rather than a new column (approved decision) — it deliberately does
 * NOT clear suspended_at/suspended_by_platform_admin_id/status_reason,
 * which remain as the historical record of the prior suspension, matching
 * this schema's already-accepted single-most-recent-event-per-column
 * design (no separate audit_logs system).
 */
class OrganizationLifecycleService
{
    /**
     * @throws InvalidOrganizationTransitionException
     */
    public function approve(Organization $organization, PlatformAdmin $admin): Organization
    {
        return $this->transition(
            $organization,
            expectedFrom: OrganizationStatus::Pending,
            to: OrganizationStatus::Active,
            applyAuditFields: function (Organization $locked) use ($admin) {
                $locked->approved_at = now();
                $locked->approved_by_platform_admin_id = $admin->id;
            },
        );
    }

    /**
     * @throws InvalidOrganizationTransitionException
     */
    public function reject(Organization $organization, PlatformAdmin $admin, string $reason): Organization
    {
        return $this->transition(
            $organization,
            expectedFrom: OrganizationStatus::Pending,
            to: OrganizationStatus::Rejected,
            applyAuditFields: function (Organization $locked) use ($admin, $reason) {
                $locked->rejected_at = now();
                $locked->rejected_by_platform_admin_id = $admin->id;
                $locked->status_reason = $reason;
            },
        );
    }

    /**
     * @throws InvalidOrganizationTransitionException
     */
    public function suspend(Organization $organization, PlatformAdmin $admin, string $reason): Organization
    {
        return $this->transition(
            $organization,
            expectedFrom: OrganizationStatus::Active,
            to: OrganizationStatus::Suspended,
            applyAuditFields: function (Organization $locked) use ($admin, $reason) {
                $locked->suspended_at = now();
                $locked->suspended_by_platform_admin_id = $admin->id;
                $locked->status_reason = $reason;
            },
        );
    }

    /**
     * suspended -> active. Reuses the approve pair (approved_at/
     * approved_by_platform_admin_id) to record the reactivation event — no
     * new column, per the approved design. suspended_at/
     * suspended_by_platform_admin_id/status_reason are deliberately left
     * untouched, preserving the prior suspension's audit trail.
     *
     * @throws InvalidOrganizationTransitionException
     */
    public function reactivate(Organization $organization, PlatformAdmin $admin): Organization
    {
        return $this->transition(
            $organization,
            expectedFrom: OrganizationStatus::Suspended,
            to: OrganizationStatus::Active,
            applyAuditFields: function (Organization $locked) use ($admin) {
                $locked->approved_at = now();
                $locked->approved_by_platform_admin_id = $admin->id;
            },
        );
    }

    /**
     * @param  callable(Organization): void  $applyAuditFields
     *
     * @throws InvalidOrganizationTransitionException
     */
    private function transition(
        Organization $organization,
        OrganizationStatus $expectedFrom,
        OrganizationStatus $to,
        callable $applyAuditFields,
    ): Organization {
        return DB::transaction(function () use ($organization, $expectedFrom, $to, $applyAuditFields) {
            /** @var Organization $locked */
            $locked = Organization::where('id', $organization->id)
                ->lockForUpdate()
                ->first();

            if ($locked->status !== $expectedFrom || ! OrganizationLifecycleTransitions::isAllowed($locked->status, $to)) {
                throw new InvalidOrganizationTransitionException($locked->status, $to);
            }

            $locked->status = $to;
            $applyAuditFields($locked);
            $locked->save();

            return $locked;
        });
    }
}
