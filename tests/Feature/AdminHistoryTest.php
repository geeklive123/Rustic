<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Order;
use App\Services\CashShiftService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class AdminHistoryTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    }

    public function test_new_admin_routes_require_authorization(): void
    {
        foreach (['/admin', '/admin/reportes', '/admin/pedidos', '/admin/ventas-sin-caja', '/admin/pedidos/1'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
        $this->actingAs($this->accountFor(Role::Seller));
        foreach (['/admin', '/admin/reportes', '/admin/pedidos', '/admin/ventas-sin-caja'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_historical_filters_detail_and_shift_isolation(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0]);
        $first = $this->payload($product, $extra, $option);
        [$old] = app(OrderService::class)->create($first, $seller);
        $old->update(['created_at' => '2026-11-06 04:00:00']);
        $second = $this->payload($product, $extra, $option);
        [$outside] = app(OrderService::class)->create($second, $seller);
        $outside->update(['created_at' => '2026-11-07 04:00:00']);
        $third = $this->payload($product, $extra, $option);
        $third['cash_shift_id'] = $shift->id;
        [$linked] = app(OrderService::class)->create($third, $seller);
        $linked->update(['created_at' => '2026-11-06 09:00:00']);
        $this->actingAs($admin);
        $this->get('/admin')->assertOk()->assertSee('Ventas sin caja');
        $response = $this->get('/admin/ventas-sin-caja?start_date=2026-11-06&end_date=2026-11-06')->assertOk()
            ->assertSee($product->name)->assertSee($extra->name)->assertSee($option->name)->assertSee($seller->name);
        $this->assertSame([$old->id], $response->viewData('orders')->pluck('id')->all());
        $result = $this->get('/admin/ventas-sin-caja?search='.$outside->id)->assertOk();
        $this->assertSame([$outside->id], $result->viewData('orders')->pluck('id')->all());
        $this->get('/admin/ventas-sin-caja?start_date=2026-11-07&end_date=2026-11-06')->assertSessionHasErrors('end_date');
        $this->get('/admin/pedidos/'.$old->id)->assertOk()->assertSee($product->name)->assertSee($seller->name);
        $this->get('/admin/caja/'.$shift->id.'/pedidos')->assertOk()->assertSee('Pedido #'.$linked->id)->assertDontSee('Pedido #'.$old->id);
        $report = $this->get('/admin/reportes?period=custom&start_date=2026-11-06&end_date=2026-11-06')->assertOk();
        $this->assertEqualsCanonicalizing([$old->id, $linked->id], $report->viewData('history')->pluck('id')->all());
        $this->assertNull(Order::findOrFail($old->id)->cash_shift_id);
    }

    public function test_unlinked_sales_are_paginated_and_keep_filters(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        for ($i = 0; $i < 21; $i++) {
            [$order] = app(OrderService::class)->create($this->payload($product, $extra, $option), $seller);
            $order->update(['created_at' => '2026-11-06 12:00:00']);
        }
        $this->actingAs($this->accountFor(Role::Administrator));
        $first = $this->get('/admin/ventas-sin-caja?end_date=2026-11-06')->assertOk();
        $this->assertSame(20, $first->viewData('orders')->count());
        $this->assertSame(21, $first->viewData('orders')->total());
        $first->assertSee('end_date=2026-11-06', false);
        $last = $this->get('/admin/ventas-sin-caja?end_date=2026-11-06&page=2')->assertOk();
        $this->assertSame(1, $last->viewData('orders')->count());
        $this->assertNotContains($last->viewData('orders')->first()->id, $first->viewData('orders')->pluck('id')->all());
    }
}
