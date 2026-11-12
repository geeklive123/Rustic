<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\CashMovementService;
use App\Services\CashShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class CashCompensationTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_closed_shift_compensation_is_audited_idempotent_and_does_not_change_cash(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000]);
        [$movement] = app(CashMovementService::class)->create($admin, $shift->id, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'type' => 'IN', 'amount_cents' => 500, 'reason' => 'Entrada']);
        [$closed] = app(CashShiftService::class)->close($admin, $shift->id, ['operation_id' => (string) Str::uuid(), 'counted_cents' => 1500]);
        $snapshot = $closed->close_snapshot;
        [$open] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 100]);
        $body = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'original_movement_id' => $movement->id, 'reason' => 'Entrada equivocada', 'confirmed' => 1];
        $url = '/admin/caja/'.$shift->id.'/compensaciones';
        $this->actingAs($seller)->post($url, $body)->assertForbidden();
        $this->actingAs($admin)->post($url, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $body)->assertRedirect();
        $this->post($url, array_replace($body, ['reason' => 'Otro']))->assertStatus(409);
        $this->post($url, array_replace($body, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid()]))->assertStatus(409);
        $this->assertDatabaseHas('cash_movements', ['original_movement_id' => $movement->id, 'type' => 'COMPENSATION', 'cash_adjustment_cents' => -500, 'created_by' => $admin->id]);
        $this->assertSame($snapshot, $shift->fresh()->close_snapshot);
        $this->assertSame(1500, $shift->fresh()->expected_cents);
        $this->assertSame(0, DB::table('cash_movements')->where('cash_shift_id', $open->id)->count());
        $this->get('/admin/caja/'.$shift->id.'/movimientos')->assertOk()->assertSee('Esperado conciliado')->assertSee('Bs 10.00');
    }
}
