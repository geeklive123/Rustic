<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\CashMovementService;
use App\Services\CashShiftService;
use App\Services\ManualQrPaymentService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class CashMovementTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    private function body(string $type, string $amount): array
    {
        return ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'type' => $type, 'amount' => $amount, 'reason' => 'Movimiento de prueba'];
    }

    public function test_cash_in_out_retry_and_close_snapshot(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $admin = $this->accountFor(Role::Administrator);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000]);
        $this->actingAs($admin);
        $url = '/admin/caja/'.$shift->id.'/movimientos';
        $entry = $this->body('IN', '5.00');
        $this->post($url, $entry)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $entry)->assertRedirect();
        $this->post($url, array_replace($entry, ['amount' => '6.00']))->assertStatus(409);
        $this->post($url, $this->body('OUT', '16.00'))->assertStatus(409);
        $this->post($url, $this->body('OUT', '7.00'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cash_movements', 2);
        $this->get($url)->assertOk()->assertSee('Movimiento de prueba');
        [$closed] = app(CashShiftService::class)->close($admin, $shift->id, ['operation_id' => (string) Str::uuid(), 'counted_cents' => 750, 'note' => 'Faltante']);
        $this->assertSame(800, $closed->expected_cents);
        $this->assertSame(-50, $closed->difference_cents);
        $snapshot = $closed->close_snapshot;
        $this->post($url, $this->body('IN', '1.00'))->assertStatus(409);
        $this->assertSame($snapshot, $shift->fresh()->close_snapshot);
        $this->post($url, $entry)->assertRedirect();
    }

    public function test_linked_verified_transfer_does_not_duplicate_sale_or_bank_total(): void
    {
        [$category, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 10000]);
        $body = $this->payload($product, $extra, $option);
        $body['payment_method'] = 'QR_STATIC';
        $body['cash_shift_id'] = $shift->id;
        [$order] = app(OrderService::class)->create($body, $seller);
        $transfer = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'type' => 'TRANSFER', 'amount_cents' => 7000, 'reason' => 'Cambio de fondos', 'bank_amount_cents' => 12400,
            'bank_reference' => 'REF-123', 'verification_evidence' => 'Abono comprobado', 'verified' => true, 'payment_id' => $order->payment->id];
        try {
            app(CashMovementService::class)->create($admin, $shift->id, $transfer);
            $this->fail('Pending QR must be rejected.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        app(ManualQrPaymentService::class)->confirm($order->uuid, ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400, 'reference' => 'REF-123'], $admin);
        app(CashMovementService::class)->create($admin, $shift->id, $transfer);
        $summary = app(CashShiftService::class)->summary($shift);
        $this->assertSame(5400, $summary['total_cents']);
        $this->assertSame(12400, $summary['bank_verified_cents']);
        $this->assertSame(7000, $summary['cash_outflows_cents']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_permissions_and_confirmation_are_required(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 10000]);
        $url = '/admin/caja/'.$shift->id.'/movimientos';
        $this->post($url, $this->body('IN', '1.00'))->assertRedirect('/admin/login');
        $this->actingAs($seller)->post($url, $this->body('IN', '1.00'))->assertForbidden();
        $this->actingAs($admin)->post($url, $this->body('TRANSFER', '1.00'))->assertSessionHasErrors(['bank_reference', 'verification_evidence', 'verified']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_simulated_qr_cannot_back_transfer_and_separate_transfer_is_reconciled(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        [$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 10000]);
        $body = $this->payload($product, $extra, $option);
        $body['cash_shift_id'] = $shift->id;
        $body['payment_method'] = 'QR_SIMULATED';
        [$order] = app(OrderService::class)->create($body, $seller);
        $transfer = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'type' => 'TRANSFER', 'amount_cents' => 2000, 'reason' => 'Cambio',
            'bank_amount_cents' => 2000, 'bank_reference' => 'REF-SEPARADA', 'verification_evidence' => 'Cuenta bancaria', 'verified' => true, 'payment_id' => $order->payment->id];
        try {
            app(CashMovementService::class)->create($admin, $shift->id, $transfer);
            $this->fail('Simulated QR must be rejected.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $transfer['payment_id'] = null;
        app(CashMovementService::class)->create($admin, $shift->id, $transfer);
        $summary = app(CashShiftService::class)->summary($shift);
        $this->assertSame(5400, $summary['methods_cents']['QR_SIMULATED']);
        $this->assertSame(2000, $summary['bank_verified_cents']);
        $this->assertSame(5400, $summary['total_cents']);
    }
}
