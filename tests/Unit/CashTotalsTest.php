<?php

namespace Tests\Unit;

use App\Services\CashTotals;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class CashTotalsTest extends TestCase
{
    public function test_integer_opening_cash_and_counted_values_produce_a_signed_difference(): void
    {
        $totals = new CashTotals;
        $this->assertSame(['expected_cents' => 6400, 'difference_cents' => -200], $totals->calculate(1000, 5400, 6200));
        $this->assertSame(['expected_cents' => 0, 'difference_cents' => 1], $totals->calculate(0, 0, 1));
    }

    public function test_negative_amounts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CashTotals)->calculate(0, -1, 0);
    }

    public function test_overflow_is_rejected(): void
    {
        $this->expectException(OverflowException::class);
        (new CashTotals)->calculate(PHP_INT_MAX, 1, 0);
    }
}
