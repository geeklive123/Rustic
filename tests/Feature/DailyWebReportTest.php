<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyWebReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Minimal isolated in-memory schema; no application migrations are run.
        DB::statement('CREATE TABLE orders (id INTEGER PRIMARY KEY, created_at TEXT, total_cents INTEGER, payment_status TEXT)');
        DB::statement('CREATE TABLE payments (id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE, method TEXT, status TEXT, amount_cents INTEGER)');
        DB::statement('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, product_id INTEGER, product_name TEXT, quantity INTEGER)');
        CarbonImmutable::setTestNow('2026-11-01 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_access_is_closed_by_default_and_forwarded_headers_cannot_bypass_it(): void
    {
        config(['reports.allowed_networks' => []]);
        $this->call('GET', '/reportes', server: ['REMOTE_ADDR' => '10.1.0.8'])->assertForbidden();
        config(['reports.allowed_networks' => ['10.1.0.0/24']]);
        $this->call('GET', '/reportes', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '10.1.0.8'])->assertForbidden();
        config(['reports.allowed_networks' => ['0.0.0.0/0', '::/0', 'invalid']]);
        $this->call('GET', '/reportes', server: ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
        config(['reports.allowed_networks' => ['10.1.0.0/24']]);
        $this->call('GET', '/reportes', server: ['REMOTE_ADDR' => '10.1.0.8'])->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->post('/reportes')->assertStatus(405);
    }

    public function test_daily_totals_ranking_sales_and_queries_are_read_only(): void
    {
        config(['reports.allowed_networks' => ['127.0.0.1']]);
        $orders = [
            [1, '2026-11-01 03:59:59', 9999, 'cash_collected'],
            [2, '2026-11-01 04:00:00', 12345, 'cash_collected'],
            [3, '2026-11-01 08:00:00', 250, 'simulated'],
            [4, '2026-11-01 09:00:00', 505, 'qr_verified'],
            [5, '2026-11-01 10:00:00', 100, 'unpaid'],
            [6, '2026-11-01 11:00:00', 300, 'qr_pending'],
            [7, '2026-11-02 04:00:00', 9999, 'cash_collected'],
        ];
        foreach ($orders as [$id, $date, $total, $status]) {
            DB::table('orders')->insert(['id' => $id, 'created_at' => $date, 'total_cents' => $total, 'payment_status' => $status]);
        }
        foreach ([[2, 'CASH', 'cash_collected', 12345], [3, 'QR_SIMULATED', 'simulated', 250], [4, 'QR_STATIC', 'qr_verified', 505], [6, 'QR_STATIC', 'qr_pending', 300]] as [$order, $method, $status, $amount]) {
            DB::table('payments')->insert(['order_id' => $order, 'method' => $method, 'status' => $status, 'amount_cents' => $amount]);
        }
        foreach ([[2, 1, 'Hamburguesa', 3], [3, 1, 'Hamburguesa', 2], [4, 2, 'Papas', 2]] as [$order, $product, $name, $quantity]) {
            DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'product_name' => $name, 'quantity' => $quantity]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->call('GET', '/reportes', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $response->assertOk()->assertSee('Bs 135,00')->assertSee('Bs 123,45')->assertSee('Bs 2,50')->assertSee('Bs 5,05')
            ->assertSee('Simulado · no es cobro real')->assertSee('Hamburguesa')->assertSee('5 unidades')
            ->assertSee('#2')->assertSee('00:00')->assertDontSee('#1')->assertDontSee('#7');
        $summary = $response->viewData('summary');
        $this->assertSame(5, $summary['order_count']);
        $this->assertSame(2, $summary['unpaid_count']);
        $this->assertSame(13500, $summary['total_cents']);
        $this->assertSame(2, $response->viewData('products')->count());
        $queries = DB::getQueryLog();
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
        }
    }

    public function test_lists_are_limited_to_five_products_and_ten_latest_sales(): void
    {
        config(['reports.allowed_networks' => ['127.0.0.1']]);
        for ($i = 1; $i <= 12; $i++) {
            DB::table('orders')->insert(['id' => $i, 'created_at' => sprintf('2026-11-01 05:%02d:00', $i), 'total_cents' => 100, 'payment_status' => 'unpaid']);
            DB::table('order_items')->insert(['order_id' => $i, 'product_id' => $i, 'product_name' => 'Producto '.$i, 'quantity' => $i]);
        }
        $response = $this->call('GET', '/reportes', server: ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();
        $this->assertSame([12, 11, 10, 9, 8], $response->viewData('products')->pluck('product_id')->all());
        $this->assertSame(range(12, 3), $response->viewData('sales')->pluck('id')->all());
    }

    public function test_all_periods_use_la_paz_dates_and_read_only_queries(): void
    {
        config(['reports.allowed_networks' => ['127.0.0.1']]);
        // Wednesday 18 November in La Paz: Monday 16 starts the week.
        CarbonImmutable::setTestNow('2026-11-18 12:00:00 UTC');
        foreach ([
            [1, '2026-10-31 04:00:00'], [2, '2026-11-01 04:00:00'],
            [3, '2026-11-16 03:59:59'], [4, '2026-11-16 04:00:00'],
            [5, '2026-11-17 03:59:59'], [6, '2026-11-17 04:00:00'],
            [7, '2026-11-18 03:59:59'], [8, '2026-11-18 04:00:00'],
            [9, '2026-11-19 03:59:59'], [10, '2026-11-19 04:00:00'],
            [11, '2026-11-23 04:00:00'], [12, '2026-12-01 04:00:00'],
        ] as [$id, $created]) {
            DB::table('orders')->insert(['id' => $id, 'created_at' => $created, 'total_cents' => $id * 100, 'payment_status' => 'unpaid']);
            DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $id, 'product_name' => 'Producto '.$id, 'quantity' => $id]);
        }
        foreach ([[4, 'CASH', 'cash_collected'], [5, 'QR_SIMULATED', 'simulated'], [6, 'QR_STATIC', 'qr_verified'], [7, 'QR_STATIC', 'qr_pending']] as [$id, $method, $status]) {
            DB::table('payments')->insert(['order_id' => $id, 'method' => $method, 'status' => $status, 'amount_cents' => $id * 100]);
        }
        foreach ([
            ['', [9, 8]],
            ['?period=today', [9, 8]],
            ['?period=yesterday', [7, 6]],
            ['?period=week', [10, 9, 8, 7, 6, 5, 4]],
            ['?period=month', [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]],
            ['?period=custom&start_date=2026-11-16&end_date=2026-11-17', [7, 6, 5, 4]],
        ] as [$query, $ids]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->call('GET', '/reportes'.$query, server: ['REMOTE_ADDR' => '127.0.0.1'])->assertOk();
            $this->assertSame($ids, $response->viewData('sales')->pluck('id')->all());
            $this->assertSame(array_sum($ids) * 100, $response->viewData('summary')['total_cents']);
            $this->assertSame(count($ids), $response->viewData('summary')['unpaid_count']);
            $this->assertSame($ids[0], $response->viewData('products')->first()->product_id);
            foreach (DB::getQueryLog() as $sql) {
                $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql['query']);
            }
        }
        $custom = $this->call('GET', '/reportes?period=custom&start_date=2026-11-16&end_date=2026-11-17', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertSame(400, $custom->viewData('summary')['cash_cents']);
        $this->assertSame(500, $custom->viewData('summary')['simulated_qr_cents']);
        $this->assertSame(600, $custom->viewData('summary')['verified_qr_cents']);
        $custom->assertSee('16/11/2026 al 17/11/2026')->assertSee('start_date=2026-11-16&amp;end_date=2026-11-17', false)
            ->assertSee('16/11/2026 00:00');
    }

    public function test_invalid_filters_are_rejected_without_querying_orders(): void
    {
        config(['reports.allowed_networks' => ['127.0.0.1']]);
        foreach ([
            '?period=other', '?period=', '?period=custom',
            '?period=custom&start_date=2026-02-30&end_date=2026-03-01',
            '?period=custom&start_date=2026-11-18&end_date=2026-11-17',
            '?period=custom&start_date=2026-11-01&end_date=invalid',
        ] as $query) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->call('GET', '/reportes'.$query, server: ['REMOTE_ADDR' => '127.0.0.1'])->assertStatus(422)->assertSee('Revise los filtros');
            $this->assertSame([], DB::getQueryLog());
        }
    }
}
