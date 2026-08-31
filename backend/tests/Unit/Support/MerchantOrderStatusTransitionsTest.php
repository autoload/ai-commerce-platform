<?php

namespace Tests\Unit\Support;

use App\Enums\OrderStatus;
use App\Support\MerchantOrderStatusTransitions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MerchantOrderStatusTransitionsTest extends TestCase
{
    public static function allowedTransitions(): array
    {
        return [
            'pending -> cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled],
            'paid -> processing' => [OrderStatus::Paid, OrderStatus::Processing],
            'processing -> shipped' => [OrderStatus::Processing, OrderStatus::Shipped],
            'shipped -> completed' => [OrderStatus::Shipped, OrderStatus::Completed],
        ];
    }

    public static function explicitlyRejectedTransitions(): array
    {
        return [
            'pending -> paid (webhook only)' => [OrderStatus::Pending, OrderStatus::Paid],
            'paid -> cancelled (authoritative invariant)' => [OrderStatus::Paid, OrderStatus::Cancelled],
            'paid -> shipped (skips processing)' => [OrderStatus::Paid, OrderStatus::Shipped],
            'processing -> completed (skips shipped)' => [OrderStatus::Processing, OrderStatus::Completed],
            'shipped -> processing (backward)' => [OrderStatus::Shipped, OrderStatus::Processing],
            'completed -> processing (terminal)' => [OrderStatus::Completed, OrderStatus::Processing],
            'completed -> refunded (webhook only)' => [OrderStatus::Completed, OrderStatus::Refunded],
            'cancelled -> pending (terminal)' => [OrderStatus::Cancelled, OrderStatus::Pending],
            'refunded -> paid (terminal)' => [OrderStatus::Refunded, OrderStatus::Paid],
            'paid -> refunded (webhook only)' => [OrderStatus::Paid, OrderStatus::Refunded],
            'processing -> refunded (webhook only)' => [OrderStatus::Processing, OrderStatus::Refunded],
            'shipped -> refunded (webhook only)' => [OrderStatus::Shipped, OrderStatus::Refunded],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_merchant_allowed_transition_is_permitted(OrderStatus $from, OrderStatus $to): void
    {
        $this->assertTrue(MerchantOrderStatusTransitions::isAllowed($from, $to));
    }

    #[DataProvider('explicitlyRejectedTransitions')]
    public function test_explicitly_named_transition_is_rejected(OrderStatus $from, OrderStatus $to): void
    {
        $this->assertFalse(MerchantOrderStatusTransitions::isAllowed($from, $to));
    }

    /**
     * Full sweep of every (from, to) pair across all seven statuses — proves
     * the whitelist is exactly the four documented edges, nothing more and
     * nothing less, rather than relying only on the hand-picked cases above.
     */
    public function test_only_the_four_documented_edges_are_allowed(): void
    {
        $allowedPairs = array_map(
            fn (array $case) => $case[0]->value.'->'.$case[1]->value,
            self::allowedTransitions()
        );

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $pair = $from->value.'->'.$to->value;
                $expected = in_array($pair, $allowedPairs, true);

                $this->assertSame(
                    $expected,
                    MerchantOrderStatusTransitions::isAllowed($from, $to),
                    "Unexpected result for transition {$pair}."
                );
            }
        }
    }
}
