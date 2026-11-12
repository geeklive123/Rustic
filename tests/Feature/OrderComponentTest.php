<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CatalogOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class OrderComponentTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    private function comboPayload($product, $extra, $option): array
    {
        $definition = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => true, 'is_active' => true]);
        $definition->extras()->attach($extra);
        $definition->options()->attach($option);

        return ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'test-components', 'customer_name' => 'Cliente',
            'payment_method' => 'CASH', 'expected_total_cents' => 2700,
            'items' => [['product_id' => $product->id, 'combo_revision' => 0, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => [], 'notes' => 'Combo',
                'components' => [
                    ['component_id' => $definition->id, 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]], 'option_ids' => [$option->id], 'notes' => 'Con tomate'],
                    ['component_id' => $definition->id, 'extras' => [], 'option_ids' => [], 'notes' => 'Sin tomate'],
                ]]]];
    }

    public function test_combo_is_priced_once_and_kitchen_and_replays_preserve_components(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $headers = $this->tokenFor($seller);
        $body = $this->comboPayload($product, $extra, $option);
        $result = $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()
            ->assertJsonPath('data.total_cents', 2700)->assertJsonPath('data.items.0.line_total_cents', 2700)
            ->assertJsonPath('data.items.0.extras.0.unit_price_cents', 200)
            ->assertJsonPath('data.items.0.components.0.extras.0.unit_price_cents', 200)
            ->assertJsonPath('data.items.0.components.1.notes', 'Sin tomate');
        $this->postJson('/api/v1/orders', $body, $headers)->assertOk()->assertJsonPath('data.number', $result->json('data.number'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('order_component_extras', 1);
        $this->app['auth']->forgetGuards();
        $kitchen = $this->tokenFor($this->accountFor(Role::Kitchen));
        $kitchenResponse = $this->getJson('/api/v1/kitchen/orders/'.$body['uuid'], $kitchen)->assertOk()
            ->assertJsonPath('data.items.0.components.0.extras.0.name', $extra->name)
            ->assertJsonPath('data.items.0.components.1.notes', 'Sin tomate')
            ->assertJsonMissingPath('data.items.0.components.0.extras.0.unit_price_cents')
            ->assertSee('Hamburguesa 1')->assertSee('Hamburguesa 2')->assertSee('Sin tomate');
        $this->assertStringContainsString('1. Hamburguesa 1 ? Extras: Queso extra', $kitchenResponse->json('data.items.0.notes'));
        $this->assertStringContainsString('2. Hamburguesa 2 ? Sin tomate', $kitchenResponse->json('data.items.0.notes'));
        $product->update(['price_cents' => 2900]);
        $extra->update(['name' => 'Nombre cambiado', 'price_cents' => 500]);
        $option->update(['name' => 'Opci?n cambiada']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/orders/'.$body['uuid'], $headers)->assertOk()->assertJsonPath('data.total_cents', 2700)
            ->assertJsonPath('data.items.0.components.0.extras.0.name', 'Queso extra')
            ->assertJsonPath('data.items.0.extras.0.unit_price_cents', 200)
            ->assertJsonPath('data.items.0.components.0.extras.0.unit_price_cents', 200)
            ->assertJsonPath('data.items.0.components.0.options.0.name', 'Con llajua');
    }

    public function test_same_extra_in_both_components_charged_twice_and_unit_split_works(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->comboPayload($product, $extra, $option);
        $body['items'][0]['components'][1]['extras'] = [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]];
        $body['expected_total_cents'] = 2900;
        $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.total_cents', 2900)
            ->assertJsonCount(2, 'data.items.0.extras')->assertJsonPath('data.items.0.extras.0.unit_price_cents', 200)->assertJsonPath('data.items.0.extras.1.unit_price_cents', 200);
        $this->assertDatabaseCount('order_component_extras', 2);
        $otherOption = CatalogOption::create(['name' => 'Sin tomate', 'is_active' => true]);
        $unit = ['product_id' => $product->id, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => [$option->id], 'quantity' => 2, 'notes' => 'Iguales'];
        $split = $this->payload($product, $extra, $option);
        $split['items'] = [$unit, array_merge($unit, ['quantity' => 1, 'option_ids' => [], 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]]]), array_merge($unit, ['quantity' => 1, 'option_ids' => [$otherOption->id]])];
        $split['expected_total_cents'] = 10200;
        $this->postJson('/api/v1/orders', $split, $headers)->assertCreated()->assertJsonPath('data.total_cents', 10200)->assertJsonCount(3, 'data.items');
    }

    public function test_invalid_component_input_and_prices_roll_back(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->comboPayload($product, $extra, $option);
        $invalid = $body;
        $invalid['items'][0]['components'] = ['foo' => $body['items'][0]['components'][0]];
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertUnprocessable();
        $invalid = $body;
        $invalid['items'][0]['quantity'] = 2;
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertUnprocessable();
        $invalid = $body;
        $invalid['items'][0]['extras'] = [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]];
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertUnprocessable();
        $invalid = $body;
        $invalid['items'][0]['components'][0]['extras'][] = $invalid['items'][0]['components'][0]['extras'][0];
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertUnprocessable();
        $invalid = $body;
        $invalid['items'][0]['components'][0]['extras'][0]['expected_unit_price_cents'] = 1;
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertStatus(409);
        $invalid = $body;
        $invalid['items'][0]['components'][0]['extras'][0]['extra_id'] = 999999;
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertUnprocessable();
        $invalid = $body;
        $invalid['items'][0]['components'][0]['option_ids'] = [$option->id, $option->id];
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertUnprocessable();
        $invalid = $body;
        $invalid['items'][0]['expected_unit_price_cents'] = 1;
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertStatus(409);
        $invalid = $body;
        $invalid['expected_total_cents'] = 2500;
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertStatus(409);
        $invalid = $body;
        $invalid['items'][0]['components'][0]['name'] = 'Falso';
        $this->postJson('/api/v1/orders', $invalid, $headers)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('order_item_components', 0);
    }

    public function test_legacy_orders_keep_legacy_shape(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.total_cents', 5400)->assertJsonMissingPath('data.items.0.components');
        $this->assertDatabaseCount('order_item_components', 0);
    }

    public function test_component_qr_in_shared_shift_is_counted_once_and_frozen(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $adminHeaders = $this->tokenFor($this->accountFor(Role::Administrator));
        $sellerHeaders = $this->tokenFor($this->accountFor(Role::Seller));
        $shift = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0], $adminHeaders)->assertCreated()->json('data.id');
        $body = $this->comboPayload($product, $extra, $option);
        $body['payment_method'] = 'QR_SIMULATED';
        $body['cash_shift_id'] = $shift;
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/orders', $body, $sellerHeaders)->assertCreated()->assertJsonPath('data.total_cents', 2700);
        $this->postJson('/api/v1/orders', $body, $sellerHeaders)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cash-shifts/'.$shift, $adminHeaders)->assertOk()->assertJsonPath('summary.methods_cents.QR_SIMULATED', 2700)->assertJsonPath('summary.order_count', 1);
        $this->postJson('/api/v1/cash-shifts/'.$shift.'/close', ['operation_id' => (string) Str::uuid(), 'counted_cents' => 0], $adminHeaders)->assertOk();
        $this->getJson('/api/v1/cash-shifts/'.$shift, $adminHeaders)->assertOk()->assertJsonPath('summary.methods_cents.QR_SIMULATED', 2700);
        $this->assertDatabaseCount('payments', 1);
    }
}
