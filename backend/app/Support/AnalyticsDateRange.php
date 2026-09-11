<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Pure, dependency-free UTC calendar-boundary resolver — same discipline as
 * MerchantOrderStatusTransitions/SalesClassification. Resolves one of the
 * five PRD §11.5 presets into a half-open [start, end) "current" window
 * plus a "previous" window, for Sales Growth.
 *
 * All boundaries are UTC — CLAUDE.md/database-design.md: no per-store or
 * per-organization timezone exists, and config('app.timezone') is already
 * 'UTC'. Every window is half-open: start <= x < end.
 *
 * Equal-duration previous-period rule, with one explicit, approved
 * exception (Analytics v1 Design Review #3): `this_month`/`last_month`
 * compare calendar month to calendar month (the universal month-over-month
 * convention), not day-count to day-count, since calendar months have
 * unequal lengths. `today`/`last_7_days`/`last_30_days` use a strictly
 * equal-length preceding window.
 */
final class AnalyticsDateRange
{
    public const PRESETS = ['today', 'last_7_days', 'last_30_days', 'this_month', 'last_month'];

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
    ) {}

    /**
     * $now is injectable for deterministic tests (this codebase otherwise
     * uses Carbon::setTestNow()/travelTo() for time control — that
     * convention still works here too, since the default resolves against
     * the real "now" exactly like every other time-sensitive call in this
     * codebase).
     */
    public static function resolve(string $preset, ?CarbonImmutable $now = null): self
    {
        if (! in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException("Unknown analytics range preset: {$preset}");
        }

        $today = ($now ?? CarbonImmutable::now())->utc()->startOfDay();

        return match ($preset) {
            'today' => new self($preset, $today, $today->addDay(), $today->subDay(), $today),
            'last_7_days' => self::rollingDays($preset, $today, 7),
            'last_30_days' => self::rollingDays($preset, $today, 30),
            'this_month' => self::calendarMonth($preset, $today, 0),
            'last_month' => self::calendarMonth($preset, $today, 1),
        };
    }

    private static function rollingDays(string $preset, CarbonImmutable $today, int $days): self
    {
        $start = $today->subDays($days - 1);
        $end = $today->addDay();

        return new self($preset, $start, $end, $start->subDays($days), $start);
    }

    private static function calendarMonth(string $preset, CarbonImmutable $today, int $monthsAgo): self
    {
        $start = $today->startOfMonth()->subMonths($monthsAgo);
        $end = $start->addMonth();

        return new self($preset, $start, $end, $start->subMonth(), $start);
    }

    /**
     * @return array{preset: string, start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }

    /**
     * @return array{start: string, end: string}
     */
    public function previousToArray(): array
    {
        return [
            'start' => $this->previousStart->toIso8601String(),
            'end' => $this->previousEnd->toIso8601String(),
        ];
    }
}
