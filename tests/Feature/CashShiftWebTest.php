<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class CashShiftWebTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    private function asHeaders(array $headers): array
    {
        $this->app['auth']->forgetGuards();

        return $headers;
    }

    public function test_all_methods_are_attributed_and_closed_totals_are_frozen(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $kitchen = $this->tokenFor($this->accountFor(Role::Kitchen));
        $open = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000, 'opening_qr_cents' => 20000];
        $shift = $this->postJson('/api/v1/cash-shifts/open', $open, $admin)->assertCreated()->assertJsonPath('data.expected_cents', 1000)->assertJsonPath('data.opening_qr_cents', 20000)->json('data.id');
        $this->postJson('/api/v1/cash-shifts/open', $open, $admin)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/cash-shifts/open', array_merge($open, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid()]), $admin)->assertStatus(409);
        $orders = [];
        foreach ([[$seller, 'CASH'], [$kitchen, 'CASH'], [$seller, 'QR_SIMULATED'], [$seller, 'QR_STATIC'], [$kitchen, 'QR_STATIC'], [$kitchen, 'UNPAID']] as [$actor, $method]) {
            $body = $this->payload($product, $extra, $option);
            $body['cash_shift_id'] = $shift;
            $body['payment_method'] = $method;
            $this->postJson('/api/v1/orders', $body, $this->asHeaders($actor))->assertCreated()->assertJsonPath('data.cash_shift_id', $shift);
            $this->postJson('/api/v1/orders', $body, $actor)->assertOk();
            $orders[] = $body;
        }
        $confirm = ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400];
        $this->postJson('/api/v1/orders/'.$orders[3]['uuid'].'/qr-confirmation', $confirm, $this->asHeaders($seller))->assertCreated();
        $this->getJson('/api/v1/cash-shifts/'.$shift.'/orders?per_page=100', $this->asHeaders($admin))->assertOk()->assertJsonPath('meta.total', 6);
        $this->getJson('/api/v1/cash-shifts/'.$shift, $admin)->assertOk()->assertJsonPath('summary.order_count', 6)
            ->assertJsonPath('summary.total_cents', 32400)->assertJsonPath('summary.methods_cents.CASH', 10800)
            ->assertJsonPath('summary.methods_cents.QR_SIMULATED', 5400)->assertJsonPath('summary.methods_cents.QR_STATIC_VERIFIED', 5400)
            ->assertJsonPath('summary.methods_cents.QR_STATIC_PENDING', 5400)->assertJsonPath('summary.methods_cents.UNPAID', 5400);
        $close = ['operation_id' => (string) Str::uuid(), 'counted_cents' => 11700];
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', $close, $admin)->assertUnprocessable();
        $close['note'] = 'Faltante contado';
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', $close, $admin)->assertOk()->assertJsonPath('data.difference_cents', -100);
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', $close, $admin)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/orders/'.$orders[4]['uuid'].'/qr-confirmation', ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400], $admin)->assertCreated();
        $this->getJson('/api/v1/cash-shifts/'.$shift, $admin)->assertOk()->assertJsonPath('summary.methods_cents.QR_STATIC_PENDING', 5400)->assertJsonPath('summary.methods_cents.QR_STATIC_VERIFIED', 5400);
        $this->getJson('/api/v1/cash-shifts/'.$shift.'/orders/'.$orders[4]['uuid'], $admin)->assertOk()->assertJsonPath('data.payment.status', 'qr_verified');
        $new = $this->payload($product, $extra, $option);
        $new['cash_shift_id'] = $shift;
        $this->postJson('/api/v1/orders', $new, $this->asHeaders($seller))->assertStatus(409);
        $this->getJson('/api/v1/kitchen/orders/'.$orders[4]['uuid'], $this->asHeaders($kitchen))->assertOk();
        $next = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0];
        $second = $this->postJson('/api/v1/cash-shifts/open', $next, $this->asHeaders($admin))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/cash-shifts/'.$second.'/orders/'.$orders[0]['uuid'], $admin)->assertNotFound();
        $this->getJson('/api/v1/cash-shifts/'.$second.'/orders', $admin)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/cash-shifts/'.$second.'/close', ['operation_id' => (string) Str::uuid(), 'counted_cents' => 0], $admin)->assertOk()->assertJsonPath('data.difference_cents', 0);
    }

    public function test_legacy_orders_and_strict_activation_and_permissions(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->postJson('/api/v1/cash-shifts/open', [], $seller)->assertForbidden();
        $this->postJson('/api/v1/cash-shifts/1/close', [], $seller)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/cash-shifts/open', [])->assertUnauthorized();
        $order = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $order, $this->asHeaders($seller))->assertCreated()->assertJsonPath('data.cash_shift_id', null);
        config()->set('cash.require_shift', true);
        $this->postJson('/api/v1/orders', $order, $seller)->assertOk();
        $this->postJson('/api/v1/orders', $this->payload($product, $extra, $option), $seller)->assertStatus(409);
        $shift = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0], $this->asHeaders($admin))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/cash-shifts/'.$shift.'/orders', $admin)->assertOk()->assertJsonPath('meta.total', 0);
        $new = $this->payload($product, $extra, $option);
        $new['cash_shift_id'] = $shift;
        $this->postJson('/api/v1/orders', $new, $this->asHeaders($seller))->assertCreated();
    }
}
