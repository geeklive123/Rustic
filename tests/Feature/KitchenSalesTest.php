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

    public function test_kitchen_qr_remains_pending_until_authorized_admin_confirms(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $kitchen = $this->tokenFor($this->accountFor(Role::Kitchen));
        $order = $this->payload($product, $extra, $option);
        $order['payment_method'] = 'QR_STATIC';
        $this->postJson('/api/v1/orders', $order, $kitchen)->assertCreated()->assertJsonPath('data.payment.status', 'qr_pending');
        $confirmation = ['operation_id' => (string) Str::uuid(), 'amount_cents' => 5400];
        $url = '/api/v1/orders/'.$order['uuid'].'/qr-confirmation';
        $this->postJson($url, $confirmation, $kitchen)->assertForbidden();
        $this->assertDatabaseHas('payments', ['method' => 'QR_STATIC', 'status' => 'qr_pending', 'confirmed_by' => null]);
        $this->app['auth']->forgetGuards();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $this->postJson($url, $confirmation, $seller)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->postJson($url, $confirmation, $admin)->assertCreated()->assertJsonPath('data.payment.status', 'qr_verified');
        $this->postJson($url, $confirmation, $admin)->assertOk()->assertJsonPath('replayed', true);
    }
}
