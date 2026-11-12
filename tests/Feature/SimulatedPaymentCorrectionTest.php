<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\CashShiftService;
use App\Services\OrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class SimulatedPaymentCorrectionTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_open_shift_correction_reclassifies_one_payment_and_replays(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000]);
        $body = $this->payload($product, $extra, $option);
        $body['payment_method'] = 'QR_SIMULATED';
        $body['cash_shift_id'] = $shift->id;
        [$order] = app(OrderService::class)->create($body, $seller);
        $change = ['operation_id' => (string) Str::uuid(), 'reason' => 'Método equivocado', 'evidence' => 'Efectivo contado', 'confirmed' => 1];
        $url = '/admin/pedidos/'.$order->id.'/corregir-qr-simulado';
        $this->actingAs($seller)->post($url, $change)->assertForbidden();
        $this->actingAs($admin)->post($url, $change)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $change)->assertRedirect();
        $this->post($url, array_replace($change, ['reason' => 'Otro']))->assertStatus(409);
        $this->assertSame('CASH', $order->fresh()->payment->method);
        $this->assertSame(5400, app(CashShiftService::class)->summary($shift)['cash_sales_cents']);
        $this->assertSame(5400, app(ReportService::class)->summary(['period' => 'today'])['cash_cents']);
        $this->assertSame(5400, app(ReportService::class)->summary(['period' => 'today'])['reclassified_payment_cents']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('order_admin_corrections', ['order_id' => $order->id, 'action' => 'payment_reclassification', 'user_id' => $admin->id]);
        [$replayed, $created] = app(OrderService::class)->create($body, $seller);
        $this->assertFalse($created);
        $this->assertSame($order->id, $replayed->id);
    }

    public function test_closed_shift_is_blocked_and_unlinked_order_stays_unlinked(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0]);
        $body = $this->payload($product, $extra, $option);
        $body['payment_method'] = 'QR_SIMULATED';
        $body['cash_shift_id'] = $shift->id;
        [$order] = app(OrderService::class)->create($body, $seller);
        [$shift] = app(CashShiftService::class)->close($admin, $shift->id, ['operation_id' => (string) Str::uuid(), 'counted_cents' => 0]);
        $snapshot = $shift->close_snapshot;
        $change = ['operation_id' => (string) Str::uuid(), 'reason' => 'Método equivocado', 'evidence' => 'Efectivo recibido', 'confirmed' => 1];
        $this->actingAs($admin)->post('/admin/pedidos/'.$order->id.'/corregir-qr-simulado', $change)->assertStatus(409);
        $this->assertSame($snapshot, $shift->fresh()->close_snapshot);
        unset($body['cash_shift_id']);
        $body['uuid'] = (string) Str::uuid();
        $body['operation_id'] = (string) Str::uuid();
        [$unlinked] = app(OrderService::class)->create($body, $seller);
        $this->post('/admin/pedidos/'.$unlinked->id.'/corregir-qr-simulado', $change)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($unlinked->fresh()->cash_shift_id);
        $this->assertNull($unlinked->fresh()->payment->cash_shift_id);
        $this->assertSame('CASH', $unlinked->fresh()->payment->method);
    }
}
