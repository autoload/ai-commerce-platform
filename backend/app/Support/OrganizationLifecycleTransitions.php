<?php

namespace App\Support;

use App\Enums\OrganizationStatus;

/**
 * Pure transition-validity helper, mirroring MerchantOrderStatusTransitions'
 * shape exactly. Encodes ONLY the four Platform-Admin-triggered edges
 * approved for Phase 9A — see docs/development/project-status.md's Phase 9A
 * design/approval entries:
 *
 *   pending   -> active     (approve)
 *   pending   -> rejected   (reject)
 *   active    -> suspended  (suspend)
 *   suspended -> active     (reactivate)
 *
 * Explicitly NOT allowed (verified by the exhaustive test sweep, not just
 * omitted by accident): rejected -> active, pending -> suspended,
 * rejected -> suspended, and every other combination. A rejected
 * organization's only future path back to active is a not-yet-built
 * re-application workflow — never a direct rejected -> active edge here.
 *
 * This table alone does not disambiguate "approve" from "reactivate" (both
 * target `active`, from different starting states) — that disambiguation
 * is OrganizationLifecycleService's job (it pins each action to its own
 * expected starting state); this class remains the single source of truth
 * for which (from, to) pairs are valid at all.
 */
final class OrganizationLifecycleTransitions
{
    /**
     * @var array<string, list<OrganizationStatus>>
     */
    private const ALLOWED = [
        OrganizationStatus::Pending->value => [OrganizationStatus::Active, OrganizationStatus::Rejected],
        OrganizationStatus::Active->value => [OrganizationStatus::Suspended],
        OrganizationStatus::Suspended->value => [OrganizationStatus::Active],
    ];

    public static function isAllowed(OrganizationStatus $from, OrganizationStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value] ?? [], true);
    }
}
