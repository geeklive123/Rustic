<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class ReportsCashTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    private function headers(Role $role): array
    {
        $this->app['auth']->forgetGuards();

        return $this->tokenFor($this->accountFor($role));
    }

    public function test_cash_shift_is_idempotent_scoped_and_never_counts_simulated_qr(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->headers(Role::Seller);
        $manager = $this->headers(Role::Administrator);
        $open = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000];
        $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => -1], $manager)->assertUnprocessable();
        $shift = $this->postJson('/api/v1/cash-shifts/open', $open, $manager)->assertCreated()->assertJsonPath('data.status', 'OPEN')->json('data.id');
        $this->postJson('/api/v1/cash-shifts/open', $open, $manager)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/cash-shifts/open', array_merge($open, ['opening_cents' => 1]), $manager)->assertStatus(409);
        $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0], $manager)->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $cash = $this->payload($product, $extra, $option);
        $cash['cash_shift_id'] = $shift;
        $this->postJson('/api/v1/orders', $cash, $seller)->assertCreated();
        $this->postJson('/api/v1/orders', $cash, $seller)->assertOk();
        $qr = $this->payload($product, $extra, $option);
        $qr['payment_method'] = 'QR_SIMULATED';
        $this->postJson('/api/v1/orders', $qr, $seller)->assertCreated();
        $this->getJson('/api/v1/cash-shifts/current', $seller)->assertOk()->assertJsonPath('data.cash_sales_cents', 5400)->assertJsonPath('data.expected_cents', 6400);
        $close = ['operation_id' => (string) Str::uuid(), 'counted_cents' => 6200, 'note' => 'Diferencia'];
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', $close, $manager)->assertOk()->assertJsonPath('data.difference_cents', -200);
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', $close, $manager)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', ['operation_id' => (string) Str::uuid(), 'counted_cents' => 6200], $manager)->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/orders', $cash, $seller)->assertOk();
        $new = $this->payload($product, $extra, $option);
        $new['cash_shift_id'] = $shift;
        $this->postJson('/api/v1/orders', $new, $seller)->assertStatus(409);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('cash_shifts', ['id' => $shift, 'cash_sales_cents' => 5400, 'expected_cents' => 6400, 'counted_cents' => 6200]);
        $this->app['auth']->forgetGuards();
        $other = $this->headers(Role::Seller);
        $this->getJson('/api/v1/cash-shifts', $other)->assertForbidden();
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', $close, $other)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $admin = $this->headers(Role::Administrator);
        $this->getJson('/api/v1/cash-shifts?per_page=1', $admin)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_reports_count_orders_once_and_preserve_financial_history_through_kitchen(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->headers(Role::Seller);
        $cash = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $cash, $seller)->assertCreated();
        $this->postJson('/api/v1/orders', $cash, $seller)->assertOk();
        $qr = $this->payload($product, $extra, $option);
        $qr['payment_method'] = 'QR_SIMULATED';
        $this->postJson('/api/v1/orders', $qr, $seller)->assertCreated();
        $unpaid = $this->payload($product, $extra, $option);
        $unpaid['payment_method'] = 'UNPAID';
        $this->postJson('/api/v1/orders', $unpaid, $seller)->assertCreated();
        $product->update(['price_cents' => 8000]);
        $this->getJson('/api/v1/reports/summary', $seller)->assertForbidden();
        $kitchen = $this->headers(Role::Kitchen);
        $this->getJson('/api/v1/reports/payments', $kitchen)->assertForbidden();
        $this->patchJson('/api/v1/kitchen/orders/'.$cash['uuid'].'/status', ['expected_status' => 'NEW', 'status' => 'PREPARING', 'operation_id' => (string) Str::uuid()], $kitchen)->assertOk();
        $this->patchJson('/api/v1/kitchen/orders/'.$cash['uuid'].'/status', ['expected_status' => 'PREPARING', 'status' => 'READY', 'operation_id' => (string) Str::uuid()], $kitchen)->assertOk();
        $this->patchJson('/api/v1/kitchen/orders/'.$cash['uuid'].'/status', ['expected_status' => 'READY', 'status' => 'PREPARING', 'operation_id' => (string) Str::uuid()], $kitchen)->assertOk();
        $this->patchJson('/api/v1/kitchen/orders/'.$cash['uuid'].'/status', ['expected_status' => 'PREPARING', 'status' => 'READY', 'operation_id' => (string) Str::uuid()], $kitchen)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/kitchen/orders/'.$cash['uuid'].'/status', ['expected_status' => 'READY', 'status' => 'DELIVERED', 'operation_id' => (string) Str::uuid()], $seller)->assertOk();
        $this->assertSame('DELIVERED', Order::where('uuid', $cash['uuid'])->firstOrFail()->kitchen_status);
        $admin = $this->headers(Role::Administrator);
        $this->getJson('/api/v1/reports/summary?period=today', $admin)->assertOk()->assertJsonPath('data.order_count', 3)
            ->assertJsonPath('data.total_cents', 16200)->assertJsonPath('data.cash_cents', 5400)
            ->assertJsonPath('data.simulated_qr_count', 1)->assertJsonPath('data.simulated_qr_cents', 5400)->assertJsonPath('data.unpaid_count', 1);
        $this->getJson('/api/v1/reports/products', $admin)->assertOk()->assertJsonPath('data.0.quantity', 6)->assertJsonPath('data.0.total_cents', 16200);
        $this->getJson('/api/v1/reports/sales?per_page=1', $admin)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reports/payments', $admin)->assertOk()->assertJsonPath('unpaid_count', 1)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reports/summary?period=custom&start_date=2000-01-01&end_date=2000-01-01', $admin)->assertOk()->assertJsonPath('data.order_count', 0);
        $this->getJson('/api/v1/reports/summary?period=custom&start_date=2026-02-02&end_date=2026-01-01', $admin)->assertUnprocessable();
    }

    public function test_private_financial_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/reports/summary')->assertUnauthorized();
        $this->getJson('/api/v1/cash-shifts/current')->assertUnauthorized();
        $headers = $this->headers(Role::Kitchen);
        $this->getJson('/api/v1/cash-shifts', $headers)->assertForbidden();
        $this->postJson('/api/v1/cash-shifts/open', [], $headers)->assertForbidden();
    }

    public function test_old_cash_without_an_explicit_shift_is_never_backfilled(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->headers(Role::Seller);
        $this->postJson('/api/v1/orders', $this->payload($product, $extra, $option), $seller)->assertCreated();
        $admin = $this->headers(Role::Administrator);
        $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 200], $admin)
            ->assertCreated()->assertJsonPath('data.cash_sales_cents', 0)->assertJsonPath('data.expected_cents', 200);
        $this->assertDatabaseHas('payments', ['cash_shift_id' => null, 'amount_cents' => 5400]);
    }

    public function test_reports_apply_local_day_boundaries_to_utc_orders(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->headers(Role::Seller);
        foreach (['2026-11-01 03:59:59', '2026-11-01 04:00:00', '2026-11-02 04:00:00'] as $date) {
            $payload = $this->payload($product, $extra, $option);
            $this->postJson('/api/v1/orders', $payload, $seller)->assertCreated();
            Order::where('uuid', $payload['uuid'])->update(['created_at' => $date]);
        }
        $admin = $this->headers(Role::Administrator);
        $this->getJson('/api/v1/reports/summary?period=custom&start_date=2026-11-01&end_date=2026-11-01', $admin)
            ->assertOk()->assertJsonPath('data.order_count', 1)->assertJsonPath('data.total_cents', 5400);
    }
}
