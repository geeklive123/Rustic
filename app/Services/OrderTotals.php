<?php

namespace App\Services;

class OrderTotals
{
    /** @param list<int> $extraPrices */
    public function line(int $productPrice, array $extraPrices, int $quantity): int
    {
        return ($productPrice + array_sum($extraPrices)) * $quantity;
    }
}
