<?php

namespace App\Services;

use InvalidArgumentException;
use OverflowException;

class CashTotals
{
    public function calculate(int $opening, int $cash, int $counted): array
    {
        if (min($opening, $cash, $counted) < 0) {
            throw new InvalidArgumentException('Cash amounts cannot be negative.');
        }
        if ($cash > PHP_INT_MAX - $opening) {
            throw new OverflowException('Cash total exceeds supported integer range.');
        }
        $expected = $opening + $cash;

        return ['expected_cents' => $expected, 'difference_cents' => $counted - $expected];
    }
}
