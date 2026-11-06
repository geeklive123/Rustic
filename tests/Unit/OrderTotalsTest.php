<?php

namespace Tests\Unit;

use App\Services\OrderTotals;
use PHPUnit\Framework\TestCase;

class OrderTotalsTest extends TestCase
{
    public function test_totals_use_integer_cents_and_apply_extras_per_unit(): void
    {
        $calculator = new OrderTotals;
        $this->assertSame(6000, $calculator->line(2500, [200, 300], 2));
        $this->assertSame(2500, $calculator->line(2500, [], 1));
        $this->assertSame(0, $calculator->line(0, [], 3));
    }
}
