<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class ManualQrPaymentTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_confirmation_permissions_replay_and_financial_reports(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $order = $this->payload($product, $extra, $option);
        $order['payment_method'] = 'QR_STATIC';
        $url = '/api/v1/orders/'.$order['uuid'].'/qr-confirmation';
        $confirmation = ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400, 'reference' => 'comprobante'];
        $adminShift = $this->tokenFor($this->accountFor(Role::Administrator));
        $shift = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 100], $adminShift)->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/orders', $order, $seller)->assertCreated()->assertJsonPath('data.payment.status', 'qr_pending');
        $this->app['auth']->forgetGuards();
        $this->postJson($url, $confirmation)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $kitchen = $this->tokenFor($this->accountFor(Role::Kitchen));
        $this->postJson($url, $confirmation, $kitchen)->assertForbidden();
        $this->getJson('/api/v1/orders/'.$order['uuid'], $kitchen)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $other = $this->tokenFor($this->accountFor(Role::Seller));
        $this->postJson($url, $confirmation, $other)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->postJson($url, array_merge($confirmation, ['amount_cents' => 1]), $seller)->assertStatus(409);
        $this->postJson($url, $confirmation, $seller)->assertCreated()->assertJsonPath('data.payment.status', 'qr_verified')->assertJsonPath('replayed', false)->assertJsonPath('data.payment.reference', 'comprobante');
        $this->postJson($url, $confirmation, $seller)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($url, array_merge($confirmation, ['reference' => 'otra']), $seller)->assertStatus(409);
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/api/v1/cash-shifts/current', $seller)->assertOk()->assertJsonPath('data.cash_sales_cents', 0)->assertJsonPath('data.expected_cents', 100);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', ['operation_id' => (string) Str::uuid(), 'counted_cents' => 100], $adminShift)->assertOk()->assertJsonPath('data.cash_sales_cents', 0);
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->getJson('/api/v1/reports/summary', $admin)->assertOk()->assertJsonPath('data.verified_qr_count', 1)->assertJsonPath('data.verified_qr_cents', 5400)->assertJsonPath('data.cash_cents', 0)->assertJsonPath('data.unpaid_count', 0);
        $this->getJson('/api/v1/reports/payments', $admin)->assertOk()->assertJsonPath('data.0.method', 'QR_STATIC')->assertJsonPath('data.0.is_actual_cash', false);
    }

    public function test_admin_confirms_pending_order_and_report_counts_pending(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $order = $this->payload($product, $extra, $option);
        $order['payment_method'] = 'QR_STATIC';
        $this->postJson('/api/v1/orders', $order, $seller)->assertCreated();
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->getJson('/api/v1/reports/summary', $admin)->assertOk()->assertJsonPath('data.unpaid_count', 1)->assertJsonPath('data.verified_qr_cents', 0);
        $this->postJson('/api/v1/orders/'.$order['uuid'].'/qr-confirmation', ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400], $admin)
            ->assertCreated()->assertJsonPath('data.payment.status', 'qr_verified');
    }

    public function test_historical_methods_are_not_confirmable_and_operation_is_unique(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $confirmation = ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400];
        foreach (['CASH', 'QR_SIMULATED', 'UNPAID', 'QR_STATIC', 'QR_STATIC'] as $index => $method) {
            $order = $this->payload($product, $extra, $option);
            $order['payment_method'] = $method;
            $this->postJson('/api/v1/orders', $order, $seller)->assertCreated();
            $response = $this->postJson('/api/v1/orders/'.$order['uuid'].'/qr-confirmation', $confirmation, $seller);
            $response->assertStatus($index === 3 ? 201 : 409);
        }
        $this->assertDatabaseHas('payments', ['method' => 'QR_SIMULATED', 'status' => 'simulated', 'confirmation_operation_id' => null]);
        $this->assertDatabaseHas('payments', ['method' => 'CASH', 'status' => 'cash_collected', 'confirmation_operation_id' => null]);
    }
}
