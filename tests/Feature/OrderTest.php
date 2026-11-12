<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Extra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_order_total_payment_snapshots_and_replay(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $headers = $this->tokenFor($seller);
        $payload = $this->payload($product, $extra, $option);
        $first = $this->postJson('/api/v1/orders', $payload, $headers)->assertCreated()->assertJsonPath('data.total_cents', 5400)
            ->assertJsonPath('data.payment.status', 'cash_collected')->assertJsonPath('data.items.0.options.0.name', 'Con llajua');
        $product->update(['price_cents' => 9999]);
        $extra->update(['price_cents' => 900]);
        $retry = $this->postJson('/api/v1/orders', $payload, $headers)->assertOk();
        $this->assertSame($first->json('data'), $retry->json('data'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/api/v1/orders/'.$payload['uuid'], $headers)->assertOk()->assertJsonPath('data.items.0.unit_price_cents', 2500)->assertJsonPath('data.items.0.extras.0.unit_price_cents', 200);
        $this->getJson('/api/v1/orders/'.$payload['uuid'].'/sync-status', $headers)->assertOk()->assertJsonPath('data.sync_status', 'synced');
    }

    public function test_conflicting_keys_uuid_and_offline_price_are_rejected_without_duplicates(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $data = $this->payload($product, $extra, $option);
        $stale = $data;
        $stale['items'][0]['expected_unit_price_cents'] = 1;
        $this->postJson('/api/v1/orders', $stale, $headers)->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/v1/orders', $data, $headers)->assertCreated();
        $changed = $data;
        $changed['customer_name'] = 'Otro';
        $this->postJson('/api/v1/orders', $changed, $headers)->assertStatus(409);
        $changed = $data;
        $changed['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $changed, $headers)->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_invalid_orders_roll_back_every_record(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $data = $this->payload($product, $extra, $option);
        $bad = $data;
        unset($bad['customer_name']);
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $bad = $data;
        $bad['items'][0]['quantity'] = 0;
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $bad = $data;
        $bad['items'][0]['product_id'] = 99999;
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $product->update(['is_active' => false]);
        $this->postJson('/api/v1/orders', $data, $headers)->assertUnprocessable();
        $product->update(['is_active' => true]);
        $unallowed = Extra::create(['name' => 'No permitido', 'price_cents' => 300, 'is_active' => true]);
        $bad = $data;
        $bad['items'][0]['extras'][0]['extra_id'] = $unallowed->id;
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $bad = $data;
        $bad['items'][0]['option_ids'] = [99999];
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_two_devices_receive_distinct_numbers_and_qr_is_simulated(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $first = $this->payload($product, $extra, $option);
        $a = $this->postJson('/api/v1/orders', $first, $headers)->assertCreated();
        $second = $this->payload($product, $extra, $option);
        $second['device_id'] = 'device-two';
        $second['payment_method'] = 'QR_SIMULATED';
        $b = $this->postJson('/api/v1/orders', $second, $headers)->assertCreated()->assertJsonPath('data.payment.status', 'simulated');
        $this->assertNotSame($a->json('data.number'), $b->json('data.number'));
    }

    public function test_extra_price_and_total_conflicts_do_not_persist_any_sale(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $data = $this->payload($product, $extra, $option);
        $data['items'][0]['extras'][0]['expected_unit_price_cents'] = 1;
        $this->postJson('/api/v1/orders', $data, $headers)->assertStatus(409);
        $data = $this->payload($product, $extra, $option);
        $data['expected_total_cents'] = 1;
        $this->postJson('/api/v1/orders', $data, $headers)->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_item_extras', 0);
    }

    public function test_sales_history_is_isolated_by_seller(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $data = $this->payload($product, $extra, $option);
        $headers = $this->tokenFor($seller);
        $this->postJson('/api/v1/orders', $data, $headers)->assertCreated();
        $this->getJson('/api/v1/orders', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->app['auth']->forgetGuards();
        $other = $this->tokenFor($this->accountFor(Role::Seller));
        $this->getJson('/api/v1/orders/'.$data['uuid'], $other)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $kitchen = $this->tokenFor($this->accountFor(Role::Kitchen));
        $this->getJson('/api/v1/orders/'.$data['uuid'], $kitchen)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->getJson('/api/v1/orders/'.$data['uuid'], $admin)->assertOk()->assertJsonPath('data.total_cents', 5400);
    }
}
