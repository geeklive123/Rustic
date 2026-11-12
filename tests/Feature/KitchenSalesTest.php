<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class KitchenSalesTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_kitchen_and_seller_sales_are_isolated_and_cash_uses_shared_shift(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $kitchen = $this->accountFor(Role::Kitchen);
        $sellerHeaders = $this->tokenFor($seller);
        $kitchenHeaders = $this->tokenFor($kitchen);
        $adminHeaders = $this->tokenFor($this->accountFor(Role::Administrator));
        $shift = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 100], $adminHeaders)->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $sellerOrder = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $sellerOrder, $sellerHeaders)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/catalog', $kitchenHeaders)->assertOk();
        $this->getJson('/api/v1/cash-shifts/current', $kitchenHeaders)->assertOk()->assertJsonPath('data.id', $shift);
        $kitchenOrder = $this->payload($product, $extra, $option);
        $kitchenOrder['cash_shift_id'] = $shift;
        $first = $this->postJson('/api/v1/orders', $kitchenOrder, $kitchenHeaders)->assertCreated()->assertJsonPath('data.total_cents', 5400)->assertJsonPath('data.payment.status', 'cash_collected');
        $this->assertSame($first->json('data'), $this->postJson('/api/v1/orders', $kitchenOrder, $kitchenHeaders)->assertOk()->json('data'));
        $this->assertDatabaseHas('orders', ['uuid' => $kitchenOrder['uuid'], 'seller_id' => $kitchen->id]);
        $this->assertDatabaseHas('payments', ['cash_shift_id' => $shift, 'amount_cents' => 5400, 'status' => 'cash_collected']);
        $this->getJson('/api/v1/orders', $kitchenHeaders)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $kitchenOrder['uuid']);
        foreach (['', '/status', '/sync-status'] as $suffix) {
            $this->getJson('/api/v1/orders/'.$kitchenOrder['uuid'].$suffix, $kitchenHeaders)->assertOk();
            $this->getJson('/api/v1/orders/'.$sellerOrder['uuid'].$suffix, $kitchenHeaders)->assertNotFound();
        }
        $this->getJson('/api/v1/kitchen/orders/'.$sellerOrder['uuid'], $kitchenHeaders)->assertOk()->assertJsonMissingPath('data.total_cents');
        $this->postJson('/api/v1/cash-shifts/open', [], $kitchenHeaders)->assertForbidden();
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', [], $kitchenHeaders)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/orders', $sellerHeaders)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $sellerOrder['uuid']);
        foreach (['', '/status', '/sync-status'] as $suffix) {
            $this->getJson('/api/v1/orders/'.$kitchenOrder['uuid'].$suffix, $sellerHeaders)->assertNotFound();
        }
        $this->getJson('/api/v1/cash-shifts/current', $sellerHeaders)->assertOk()->assertJsonPath('data.cash_sales_cents', 5400);
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->getJson('/api/v1/orders', $admin)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/orders/'.$kitchenOrder['uuid'], $admin)->assertOk();
        $this->getJson('/api/v1/orders/'.$sellerOrder['uuid'], $admin)->assertOk();
    }

    public function test_kitchen_confirms_own_qr_and_cannot_confirm_another_sellers_order(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $kitchenUser = $this->accountFor(Role::Kitchen);
        $kitchen = $this->tokenFor($kitchenUser);
        $order = $this->payload($product, $extra, $option);
        $order['payment_method'] = 'QR_STATIC';
        $this->postJson('/api/v1/orders', $order, $kitchen)->assertCreated()->assertJsonPath('data.payment.status', 'qr_pending');
        $confirmation = ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400];
        $url = '/api/v1/orders/'.$order['uuid'].'/qr-confirmation';
        $this->postJson($url, array_merge($confirmation, ['amount_cents' => 1]), $kitchen)->assertStatus(409);
        $this->assertDatabaseHas('payments', ['method' => 'QR_STATIC', 'status' => 'qr_pending', 'confirmed_by' => null]);
        $this->app['auth']->forgetGuards();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $this->postJson($url, $confirmation, $seller)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->postJson($url, $confirmation, $kitchen)->assertCreated()->assertJsonPath('data.payment.method', 'QR_STATIC')
            ->assertJsonPath('data.payment.status', 'qr_verified')->assertJsonPath('data.seller_id', $kitchenUser->id);
        $this->postJson($url, $confirmation, $kitchen)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('payments', ['method' => 'QR_STATIC', 'status' => 'qr_verified', 'amount_cents' => 5400, 'confirmed_by' => $kitchenUser->id]);
        $this->assertDatabaseCount('payments', 1);
        $this->app['auth']->forgetGuards();
        $otherOrder = $this->payload($product, $extra, $option);
        $otherOrder['payment_method'] = 'QR_STATIC';
        $this->postJson('/api/v1/orders', $otherOrder, $seller)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/orders/'.$otherOrder['uuid'].'/qr-confirmation', ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400], $kitchen)->assertNotFound();
    }

    public function test_kitchen_sales_and_manual_qr_confirmation_preserve_shift_and_reports(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $kitchenUser = $this->accountFor(Role::Kitchen);
        $kitchen = $this->tokenFor($kitchenUser);
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $shift = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 100], $admin)->assertCreated()->json('data.id');
        foreach (['CASH' => 'cash_collected', 'QR_SIMULATED' => 'simulated', 'QR_STATIC' => 'qr_pending'] as $method => $status) {
            $body = $this->payload($product, $extra, $option);
            $body['payment_method'] = $method;
            $body['cash_shift_id'] = $shift;
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/orders', $body, $kitchen)->assertCreated()->assertJsonPath('data.payment.status', $status)
                ->assertJsonPath('data.cash_shift_id', $shift)->assertJsonPath('data.seller_id', $kitchenUser->id);
            $this->postJson('/api/v1/orders', $body, $kitchen)->assertOk();
            if ($method !== 'CASH') {
                $confirmation = ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400, 'reference' => 'Abono comprobado'];
                $url = '/api/v1/orders/'.$body['uuid'].'/qr-confirmation';
                if ($method === 'QR_SIMULATED') {
                    $this->postJson($url, $confirmation, $kitchen)->assertStatus(409);
                } else {
                    $this->postJson($url, $confirmation, $kitchen)->assertCreated()->assertJsonPath('data.payment.status', 'qr_verified')
                        ->assertJsonPath('data.payment.amount_cents', 5400)->assertJsonPath('data.cash_shift_id', $shift);
                    $this->postJson($url, $confirmation, $kitchen)->assertOk()->assertJsonPath('replayed', true);
                }
            }
        }
        $this->assertDatabaseCount('orders', 3);
        $this->assertDatabaseCount('payments', 3);
        foreach ([$kitchen, $seller] as $headers) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/cash-shifts/open', [], $headers)->assertForbidden();
            $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', [], $headers)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cash-shifts/'.$shift, $admin)->assertOk()->assertJsonPath('summary.methods_cents.CASH', 5400)
            ->assertJsonPath('summary.methods_cents.QR_SIMULATED', 5400)->assertJsonPath('summary.methods_cents.QR_STATIC_VERIFIED', 5400)
            ->assertJsonPath('data.expected_cents', 5500);
        $this->getJson('/api/v1/reports/summary', $admin)->assertOk()->assertJsonPath('data.cash_cents', 5400)
            ->assertJsonPath('data.simulated_qr_cents', 5400)->assertJsonPath('data.verified_qr_cents', 5400);
    }
}
