<?php

namespace App\Support\Analytics;

/**
 * Readonly result of AnalyticsService::getSalesSummary(). Money fields are
 * floats, rounded to 2 decimal places by the service — the Controller
 * layer is responsible for wire-format string conversion, the same
 * separation OrderResource/CustomerResource already keep between
 * computation and JSON shaping.
 */
final class SalesSummary
{
    public function __construct(
        public readonly float $grossSales,
        public readonly float $salesRefunds,
        public readonly float $netSales,
        public readonly int $orderCount,
        public readonly ?float $aov,
        public readonly ?float $growthPercent,
    ) {}
}
