<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\CashShiftService;
use App\Services\OrderService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class AdminOrderCorrectionTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    }

    public function test_filters_by_worker_payment_state_kitchen_and_shift(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $other = $this->accountFor(Role::Kitchen);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0]);
        $a = $this->payload($product, $extra, $option);
        $a['cash_shift_id'] = $shift->id;
        [$linked] = app(OrderService::class)->create($a, $seller);
        $b = $this->payload($product, $extra, $option);
        $b['payment_method'] = 'QR_SIMULATED';
        [$unlinked] = app(OrderService::class)->create($b, $other);
        $unlinked->update(['kitchen_status' => 'PREPARING']);
        $this->actingAs($admin);
        $response = $this->get('/admin/pedidos?'.http_build_query(['seller_id' => $other->id, 'method' => 'QR_SIMULATED', 'payment_status' => 'simulated', 'kitchen_status' => 'PREPARING', 'shift' => 'none']))->assertOk();
        $this->assertSame([$unlinked->id], $response->viewData('orders')->pluck('id')->all());
        $response = $this->get('/admin/pedidos?shift='.$shift->id.'&method=CASH')->assertOk();
        $this->assertSame([$linked->id], $response->viewData('orders')->pluck('id')->all());
        $this->get('/admin/pedidos?method=invalid')->assertSessionHasErrors('method');
    }

    public function test_note_change_is_audited_idempotent_and_visible_to_kitchen(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $kitchen = $this->accountFor(Role::Kitchen);
        $body = $this->payload($product, $extra, $option);
        [$order] = app(OrderService::class)->create($body, $seller);
        $originalId = $order->uuid;
        $change = ['operation_id' => (string) Str::uuid(), 'expected_status' => 'NEW', 'expected_note_hash' => hash('sha256', $order->notes ?? ''), 'notes' => 'Sin cebolla', 'reason' => 'Correcci?n solicitada'];
        $this->actingAs($admin)->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertRedirect();
        $this->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertRedirect();
        $this->assertDatabaseCount('order_admin_corrections', 1);
        $this->assertDatabaseHas('order_admin_corrections', ['order_id' => $order->id, 'user_id' => $admin->id, 'action' => 'preparation_note', 'after_value' => 'Sin cebolla']);
        $this->assertSame($originalId, $order->fresh()->uuid);
        $this->assertSame(5400, $order->fresh()->total_cents);
        $this->post('/admin/pedidos/'.$order->id.'/observaciones', array_merge($change, ['notes' => 'Otro']))->assertStatus(409);
        $stale = array_merge($change, ['operation_id' => (string) Str::uuid(), 'notes' => 'Otra']);
        $this->post('/admin/pedidos/'.$order->id.'/observaciones', $stale)->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/kitchen/orders/'.$order->uuid, $this->tokenFor($kitchen))->assertOk()->assertJsonPath('data.notes', 'Sin cebolla')->assertJsonPath('data.kitchen_status', 'NEW');
        [$retry, $created] = app(OrderService::class)->create($body, $seller);
        $this->assertFalse($created);
        $this->assertSame($order->id, $retry->id);
    }

    public function test_confirmation_state_permissions_and_csrf(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$order] = app(OrderService::class)->create($this->payload($product, $extra, $option), $seller);
        $order->update(['kitchen_status' => 'PREPARING']);
        $change = ['operation_id' => (string) Str::uuid(), 'expected_status' => 'PREPARING', 'expected_note_hash' => hash('sha256', $order->notes ?? ''), 'notes' => 'Nota', 'reason' => 'Correcci?n'];
        $this->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertRedirect('/admin/login');
        $this->actingAs($seller)->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertForbidden();
        $this->actingAs($admin)->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertSessionHasErrors('confirmed');
        $change['confirmed'] = '1';
        $this->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertRedirect();
        $order->update(['kitchen_status' => 'DELIVERED']);
        $change['operation_id'] = (string) Str::uuid();
        $this->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertStatus(409);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/admin/pedidos/'.$order->id.'/observaciones', $change)->assertStatus(419);
        $this->post('/reportes/observaciones', $change)->assertStatus(404);
    }

    public function test_financial_and_cancellation_actions_are_unavailable_and_closed_totals_remain_frozen(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $service = app(CashShiftService::class);
        [$shift] = $service->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0]);
        $body = $this->payload($product, $extra, $option);
        $body['payment_method'] = 'QR_SIMULATED';
        $body['cash_shift_id'] = $shift->id;
        [$order] = app(OrderService::class)->create($body, $seller);
        [$shift] = $service->close($admin, $shift->id, ['operation_id' => (string) Str::uuid(), 'counted_cents' => 0]);
        $snapshot = $shift->close_snapshot;
        $this->actingAs($admin);
        $this->post('/admin/pedidos/'.$order->id.'/pago', ['method' => 'CASH', 'reason' => 'Prueba'])->assertNotFound();
        $this->post('/admin/pedidos/'.$order->id.'/anular', ['reason' => 'Prueba'])->assertNotFound();
        $this->assertSame('QR_SIMULATED', $order->fresh()->payment->method);
        $this->assertSame($snapshot, $shift->fresh()->close_snapshot);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->get('/admin/pedidos/'.$order->id)->assertOk()->assertSee('anulaci?n y las devoluciones est?n bloqueadas');
    }
}
