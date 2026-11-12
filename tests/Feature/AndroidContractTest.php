<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class AndroidContractTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_android_order_body_is_idempotent_and_sync_status_confirms_the_number(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $uuid = (string) Str::uuid();
        $operation = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'operation_id' => $operation, 'device_id' => 'android-test-uuid',
            'customer_name' => 'Temporal', 'notes' => null, 'payment_method' => 'CASH', 'expected_total_cents' => 5400,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_unit_price_cents' => 2500,
                'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]], 'option_ids' => [$option->id], 'notes' => null]]];
        $first = $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.operation_id', $operation);
        $retry = $this->postJson('/api/v1/orders', $body, $headers)->assertOk()->assertJsonPath('data.uuid', $uuid);
        $this->assertSame($first->json('data.number'), $retry->json('data.number'));
        $this->getJson('/api/v1/orders/'.$uuid.'/sync-status', $headers)->assertOk()
            ->assertJsonPath('data.sync_status', 'synced')->assertJsonPath('data.number', $first->json('data.number'));
        $body['expected_total_cents'] = 1;
        $this->postJson('/api/v1/orders', $body, $headers)->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_legacy_android_payment_methods_remain_unlinked_with_an_open_shift(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        config()->set('cash.require_shift', false);
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $shift = $this->postJson('/api/v1/cash-shifts/open', [
            'uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0,
        ], $admin)->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        foreach (['CASH' => 'cash_collected', 'QR_SIMULATED' => 'simulated', 'QR_STATIC' => 'qr_pending'] as $method => $status) {
            $body = $this->payload($product, $extra, $option);
            $body['payment_method'] = $method;
            $response = $this->postJson('/api/v1/orders', $body, $seller)->assertCreated()
                ->assertJsonPath('data.cash_shift_id', null)->assertJsonPath('data.payment.status', $status);
            $number = $response->json('data.number');
            $this->postJson('/api/v1/orders', $body, $seller)->assertOk()->assertJsonPath('data.number', $number);
            $this->getJson('/api/v1/orders/'.$body['uuid'].'/sync-status', $seller)->assertOk()->assertJsonPath('data.number', $number);
        }
        $this->assertDatabaseCount('orders', 3);
        $this->assertDatabaseCount('payments', 3);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cash-shifts/'.$shift.'/orders', $admin)->assertOk()->assertJsonPath('meta.total', 0);
    }
}
