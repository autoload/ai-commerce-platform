<?php

namespace Tests\Unit\Support;

use App\Support\AnalyticsDateRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pure date-math correctness — no DB/HTTP involved. $now is fixed at
 * 2026-09-15T14:30:00Z (a Tuesday, mid-month) so every preset's boundary
 * arithmetic is exercised deterministically, including the calendar-month
 * "equal duration" exception (Analytics v1 Design Review #3).
 */
class AnalyticsDateRangeTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-15T14:30:00Z');
    }

    public function test_today_is_the_current_utc_calendar_day_with_yesterday_as_previous(): void
    {
        $range = AnalyticsDateRange::resolve('today', $this->now);

        $this->assertSame('2026-09-15T00:00:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-09-16T00:00:00+00:00', $range->end->toIso8601String());
        $this->assertSame('2026-09-14T00:00:00+00:00', $range->previousStart->toIso8601String());
        $this->assertSame('2026-09-15T00:00:00+00:00', $range->previousEnd->toIso8601String());
    }

    public function test_last_7_days_includes_today_plus_the_prior_6_calendar_days(): void
    {
        $range = AnalyticsDateRange::resolve('last_7_days', $this->now);

        $this->assertSame('2026-09-09T00:00:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-09-16T00:00:00+00:00', $range->end->toIso8601String());
        $this->assertSame('2026-09-02T00:00:00+00:00', $range->previousStart->toIso8601String());
        $this->assertSame('2026-09-09T00:00:00+00:00', $range->previousEnd->toIso8601String());
    }

    public function test_last_30_days_includes_today_plus_the_prior_29_calendar_days(): void
    {
        $range = AnalyticsDateRange::resolve('last_30_days', $this->now);

        $this->assertSame('2026-08-17T00:00:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-09-16T00:00:00+00:00', $range->end->toIso8601String());
        $this->assertSame('2026-07-18T00:00:00+00:00', $range->previousStart->toIso8601String());
        $this->assertSame('2026-08-17T00:00:00+00:00', $range->previousEnd->toIso8601String());
    }

    public function test_this_month_is_the_current_calendar_month_with_the_previous_calendar_month_as_comparison(): void
    {
        $range = AnalyticsDateRange::resolve('this_month', $this->now);

        $this->assertSame('2026-09-01T00:00:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-10-01T00:00:00+00:00', $range->end->toIso8601String());
        // Calendar-month exception (not day-count-equal): previous = August, 31 days vs September's 30.
        $this->assertSame('2026-08-01T00:00:00+00:00', $range->previousStart->toIso8601String());
        $this->assertSame('2026-09-01T00:00:00+00:00', $range->previousEnd->toIso8601String());
    }

    public function test_last_month_is_the_prior_calendar_month_with_the_month_before_that_as_comparison(): void
    {
        $range = AnalyticsDateRange::resolve('last_month', $this->now);

        $this->assertSame('2026-08-01T00:00:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-09-01T00:00:00+00:00', $range->end->toIso8601String());
        $this->assertSame('2026-07-01T00:00:00+00:00', $range->previousStart->toIso8601String());
        $this->assertSame('2026-08-01T00:00:00+00:00', $range->previousEnd->toIso8601String());
    }

    public function test_a_non_utc_now_is_normalized_to_utc_before_computing_boundaries(): void
    {
        // 2026-09-15T23:30:00-05:00 is already 2026-09-16 in UTC.
        $nowInAnotherOffset = CarbonImmutable::parse('2026-09-15T23:30:00-05:00');

        $range = AnalyticsDateRange::resolve('today', $nowInAnotherOffset);

        $this->assertSame('2026-09-16T00:00:00+00:00', $range->start->toIso8601String());
    }

    public function test_unknown_preset_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AnalyticsDateRange::resolve('this_quarter');
    }

    public function test_to_array_shapes(): void
    {
        $range = AnalyticsDateRange::resolve('today', $this->now);

        $this->assertSame([
            'preset' => 'today',
            'start' => '2026-09-15T00:00:00+00:00',
            'end' => '2026-09-16T00:00:00+00:00',
        ], $range->toArray());

        $this->assertSame([
            'start' => '2026-09-14T00:00:00+00:00',
            'end' => '2026-09-15T00:00:00+00:00',
        ], $range->previousToArray());
    }
}
