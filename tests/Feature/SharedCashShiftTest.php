<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class SharedCashShiftTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_two_sellers_share_cash_and_reports_keep_attribution_and_qr_separate(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $first = $this->accountFor(Role::Seller);
        $second = $this->accountFor(Role::Seller);
        $a = $this->tokenFor($first);
        $b = $this->tokenFor($second);
        $manager = $this->tokenFor($this->accountFor(Role::Administrator));
        $open = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000];
        $id = $this->postJson('/api/v1/cash-shifts/open', $open, $manager)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/cash-shifts/open', $open, $manager)->assertOk()->assertJsonPath('replayed', true);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cash-shifts/current', $b)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0], $b)->assertForbidden();
        foreach ([[$a, $first], [$b, $second]] as [$headers, $seller]) {
            $this->app['auth']->forgetGuards();
            $cash = $this->payload($product, $extra, $option);
            $cash['cash_shift_id'] = $id;
            $this->postJson('/api/v1/orders', $cash, $headers)->assertCreated();
            $this->postJson('/api/v1/orders', $cash, $headers)->assertOk();
            $this->assertDatabaseHas('orders', ['uuid' => $cash['uuid'], 'seller_id' => $seller->id]);
        }
        foreach (['QR_STATIC', 'QR_SIMULATED'] as $method) {
            $qr = $this->payload($product, $extra, $option);
            $qr['payment_method'] = $method;
            $this->postJson('/api/v1/orders', $qr, $b)->assertCreated();
            if ($method === 'QR_STATIC') {
                $this->postJson('/api/v1/orders/'.$qr['uuid'].'/qr-confirmation', ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400], $b)->assertCreated();
            }
        }
        $this->getJson('/api/v1/cash-shifts/current', $b)->assertOk()->assertJsonPath('data.cash_sales_cents', 10800)->assertJsonPath('data.expected_cents', 11800);
        $close = ['operation_id' => (string) Str::uuid(), 'counted_cents' => 11800];
        $this->postJson('/api/v1/cash-shifts/'.$id.'/close', $close, $b)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/cash-shifts/'.$id.'/close', $close, $manager)->assertOk()->assertJsonPath('data.cash_sales_cents', 10800);
        $this->postJson('/api/v1/cash-shifts/'.$id.'/close', $close, $manager)->assertOk()->assertJsonPath('replayed', true);
        $this->app['auth']->forgetGuards();
        $new = $this->payload($product, $extra, $option);
        $new['cash_shift_id'] = $id;
        $this->postJson('/api/v1/orders', $new, $b)->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->getJson('/api/v1/reports/summary', $admin)->assertOk()->assertJsonPath('data.total_cents', 21600)
            ->assertJsonPath('data.cash_cents', 10800)->assertJsonPath('data.qr_cents', 10800)
            ->assertJsonPath('data.sellers.0.seller_id', $first->id)->assertJsonPath('data.sellers.0.order_count', 1)
            ->assertJsonPath('data.sellers.1.seller_id', $second->id)->assertJsonPath('data.sellers.1.order_count', 3)
            ->assertJsonPath('data.sellers.1.total_cents', 16200);
    }

    public function test_administrator_can_close_another_administrators_shift(): void
    {
        $seller = $this->tokenFor($this->accountFor(Role::Administrator));
        $id = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 100], $seller)->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $adminUser = $this->accountFor(Role::Administrator);
        $admin = $this->tokenFor($adminUser);
        $this->postJson('/api/v1/cash-shifts/'.$id.'/close', ['operation_id' => (string) Str::uuid(), 'counted_cents' => 100], $admin)->assertOk();
        $this->assertDatabaseHas('cash_shifts', ['id' => $id, 'closed_by' => $adminUser->id]);
    }
}
