<?php

namespace Tests\Unit;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase
{
    public function test_local_calendar_boundaries_are_utc_and_end_exclusive(): void
    {
        CarbonImmutable::setTestNow('2026-11-06 03:00:00 UTC');
        try {
            $service = new ReportService;
            [$start, $end] = $service->bounds(['period' => 'today']);
            $this->assertSame('2026-11-05 04:00:00', $start->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-06 04:00:00', $end->format('Y-m-d H:i:s'));
            [$start, $end] = $service->bounds(['period' => 'yesterday']);
            $this->assertSame('2026-11-04 04:00:00', $start->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-05 04:00:00', $end->format('Y-m-d H:i:s'));
            [$start, $end] = $service->bounds(['period' => 'week']);
            $this->assertSame('2026-11-02 04:00:00', $start->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-09 04:00:00', $end->format('Y-m-d H:i:s'));
            [$start, $end] = $service->bounds(['period' => 'month']);
            $this->assertSame('2026-11-01 04:00:00', $start->format('Y-m-d H:i:s'));
            $this->assertSame('2026-12-01 04:00:00', $end->format('Y-m-d H:i:s'));
            [$start, $end] = $service->bounds(['period' => 'custom', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02']);
            $this->assertSame('2026-11-01 04:00:00', $start->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-03 04:00:00', $end->format('Y-m-d H:i:s'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
