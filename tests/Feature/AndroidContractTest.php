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
}
