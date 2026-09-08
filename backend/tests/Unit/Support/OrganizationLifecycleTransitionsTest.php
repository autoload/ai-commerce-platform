<?php

namespace Tests\Unit\Support;

use App\Enums\OrganizationStatus;
use App\Support\OrganizationLifecycleTransitions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationLifecycleTransitionsTest extends TestCase
{
    public static function allowedTransitions(): array
    {
        return [
            'pending -> active (approve)' => [OrganizationStatus::Pending, OrganizationStatus::Active],
            'pending -> rejected (reject)' => [OrganizationStatus::Pending, OrganizationStatus::Rejected],
            'active -> suspended (suspend)' => [OrganizationStatus::Active, OrganizationStatus::Suspended],
            'suspended -> active (reactivate)' => [OrganizationStatus::Suspended, OrganizationStatus::Active],
        ];
    }

    public static function explicitlyRejectedTransitions(): array
    {
        return [
            'rejected -> active (no re-application workflow in Phase 9A)' => [OrganizationStatus::Rejected, OrganizationStatus::Active],
            'pending -> suspended (must go through active first)' => [OrganizationStatus::Pending, OrganizationStatus::Suspended],
            'rejected -> suspended' => [OrganizationStatus::Rejected, OrganizationStatus::Suspended],
            'active -> rejected' => [OrganizationStatus::Active, OrganizationStatus::Rejected],
            'active -> pending (backward)' => [OrganizationStatus::Active, OrganizationStatus::Pending],
            'suspended -> rejected' => [OrganizationStatus::Suspended, OrganizationStatus::Rejected],
            'suspended -> pending (backward)' => [OrganizationStatus::Suspended, OrganizationStatus::Pending],
            'rejected -> pending (backward)' => [OrganizationStatus::Rejected, OrganizationStatus::Pending],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_approved_transition_is_permitted(OrganizationStatus $from, OrganizationStatus $to): void
    {
        $this->assertTrue(OrganizationLifecycleTransitions::isAllowed($from, $to));
    }

    #[DataProvider('explicitlyRejectedTransitions')]
    public function test_explicitly_named_transition_is_rejected(OrganizationStatus $from, OrganizationStatus $to): void
    {
        $this->assertFalse(OrganizationLifecycleTransitions::isAllowed($from, $to));
    }

    /**
     * Full sweep of every (from, to) pair across all four statuses — proves
     * the whitelist is exactly the four approved edges, nothing more and
     * nothing less, rather than relying only on the hand-picked cases
     * above.
     */
    public function test_only_the_four_approved_edges_are_allowed(): void
    {
        $allowedPairs = array_map(
            fn (array $case) => $case[0]->value.'->'.$case[1]->value,
            self::allowedTransitions()
        );

        foreach (OrganizationStatus::cases() as $from) {
            foreach (OrganizationStatus::cases() as $to) {
                $pair = $from->value.'->'.$to->value;
                $expected = in_array($pair, $allowedPairs, true);

                $this->assertSame(
                    $expected,
                    OrganizationLifecycleTransitions::isAllowed($from, $to),
                    "Unexpected result for transition {$pair}."
                );
            }
        }
    }
}
