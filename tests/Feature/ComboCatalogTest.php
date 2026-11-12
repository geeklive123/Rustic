<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CatalogOption;
use App\Models\Extra;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class ComboCatalogTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    }

    private function body($product, int $id, $extra, $option): array
    {
        return ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'combo-test', 'customer_name' => 'Cliente', 'payment_method' => 'CASH', 'expected_total_cents' => 2700,
            'items' => [['product_id' => $product->id, 'combo_revision' => (int) $product->fresh()->combo_revision, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => [],
                'components' => [
                    ['component_id' => $id, 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]], 'option_ids' => [$option->id], 'notes' => 'Con tomate'],
                    ['component_id' => $id, 'extras' => [], 'option_ids' => [], 'notes' => 'Sin tomate'],
                ]]]];
    }

    public function test_admin_can_define_update_and_deactivate_without_duplicates(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $this->get('/admin/catalogo/products/'.$product->id.'/composicion')->assertRedirect('/admin/login');
        $this->actingAs($seller)->get('/admin/catalogo/products/'.$product->id.'/composicion')->assertForbidden();
        $this->actingAs($this->accountFor(Role::Administrator));
        $data = ['revision' => 0, 'name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => 1, 'is_active' => 1, 'extra_ids' => [$extra->id], 'option_ids' => [$option->id]];
        $url = '/admin/catalogo/products/'.$product->id.'/composicion';
        $this->get($url.'/nuevo')->assertOk();
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $data)->assertStatus(409);
        $definition = $product->comboComponents()->firstOrFail();
        $this->assertSame(2, $definition->quantity);
        $this->assertSame(1, $product->fresh()->comboComponents()->count());
        $this->get($url)->assertOk()->assertSee('Hamburguesa');
        $this->get($url.'/'.$definition->id.'/editar')->assertOk();
        $data['revision'] = 1;
        $data['is_active'] = 0;
        $this->post($url.'/'.$definition->id, $data)->assertRedirect();
        $this->assertFalse($definition->fresh()->is_active);
        $this->assertSame(2, (int) $product->fresh()->combo_revision);
    }

    public function test_component_ownership_quantities_authorizations_and_recipe_revision(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $definition = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => true, 'is_active' => true]);
        $definition->extras()->attach($extra);
        $definition->options()->attach($option);
        $unallowed = Extra::create(['name' => 'Otro', 'price_cents' => 100, 'is_active' => true]);
        $product->extras()->attach($unallowed);
        $otherOption = CatalogOption::create(['name' => 'No autorizado', 'is_active' => true]);
        $otherProduct = Product::create(['name' => 'Otro combo', 'category_id' => $product->category_id, 'price_cents' => 1000, 'is_active' => true]);
        $foreign = $otherProduct->comboComponents()->create(['name' => 'Ajeno', 'quantity' => 1, 'customizable' => true, 'is_active' => true]);
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->body($product, $definition->id, $extra, $option);
        $catalog = $this->getJson('/api/v1/catalog', $headers)->assertOk();
        $catalog->assertJsonPath('data.products.0.combo_revision', 0)->assertJsonPath('data.products.0.combo_components.0.id', $definition->id)->assertJsonPath('data.products.0.combo_components.0.quantity', 2)->assertJsonPath('data.products.0.combo_components.0.extra_ids.0', $extra->id);
        $bad = $body;
        $bad['items'][0]['components'][1]['component_id'] = $foreign->id;
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $bad = $body;
        array_pop($bad['items'][0]['components']);
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $bad = $body;
        $bad['items'][0]['components'][0]['extras'][0]['extra_id'] = $unallowed->id;
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $bad = $body;
        $bad['items'][0]['components'][0]['option_ids'] = [$otherOption->id];
        $this->postJson('/api/v1/orders', $bad, $headers)->assertUnprocessable();
        $bad = $body;
        $bad['items'][0]['combo_revision'] = 9;
        $this->postJson('/api/v1/orders', $bad, $headers)->assertStatus(409);
        $response = $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.items.0.components.0.name', 'Hamburguesa 1')->assertJsonPath('data.total_cents', 2700);
        $definition->update(['name' => 'Otro nombre', 'quantity' => 1, 'customizable' => false]);
        $product->combo_revision = 1;
        $product->save();
        $this->postJson('/api/v1/orders', $body, $headers)->assertOk()->assertJsonPath('data.number', $response->json('data.number'))->assertJsonPath('data.items.0.components.0.name', 'Hamburguesa 1');
        $bad = $body;
        $bad['uuid'] = (string) Str::uuid();
        $bad['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $bad, $headers)->assertStatus(409);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_unconfigured_product_rejects_arbitrary_components_but_accepts_legacy_order(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->body($product, 999, $extra, $option);
        $this->postJson('/api/v1/orders', $body, $headers)->assertUnprocessable();
        $legacy = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $legacy, $headers)->assertCreated()->assertJsonMissingPath('data.items.0.components');
    }

    public function test_noncustomizable_component_rejects_options_extras_and_notes(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $definition = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => false, 'is_active' => true]);
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->body($product, $definition->id, $extra, $option);
        $this->postJson('/api/v1/orders', $body, $headers)->assertUnprocessable();
        foreach ($body['items'][0]['components'] as &$component) {
            $component['extras'] = [];
            $component['option_ids'] = [];
            $component['notes'] = null;
        }
        unset($component);
        $body['expected_total_cents'] = 2500;
        $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.total_cents', 2500);
    }

    public function test_multiple_definitions_require_each_quantity_and_preserve_snapshot(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $first = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => true, 'is_active' => true]);
        $first->extras()->attach($extra);
        $first->options()->attach($option);
        $second = $product->comboComponents()->create(['name' => 'Papas', 'quantity' => 1, 'customizable' => false, 'is_active' => true]);
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $body = $this->body($product, $first->id, $extra, $option);
        $this->postJson('/api/v1/orders', $body, $headers)->assertUnprocessable();
        $body['items'][0]['components'][] = ['component_id' => $second->id, 'extras' => [], 'option_ids' => []];
        $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.items.0.components.2.name', 'Papas');
        $second->update(['name' => 'Papas nuevas', 'is_active' => false]);
        $this->getJson('/api/v1/orders/'.$body['uuid'], $headers)->assertOk()->assertJsonPath('data.items.0.components.2.name', 'Papas');
    }

    public function test_previously_accepted_named_component_payload_can_replay_without_being_accepted_as_new(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $body = $this->payload($product, $extra, $option);
        $legacy = $body;
        $legacy['items'][0]['quantity'] = 1;
        $legacy['items'][0]['extras'] = [];
        $legacy['items'][0]['option_ids'] = [];
        $legacy['items'][0]['components'] = [['name' => 'Hamburguesa', 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]], 'option_ids' => [$option->id]]];
        $legacy['expected_total_cents'] = 2700;
        $regular = $legacy;
        unset($regular['items'][0]['components']);
        $regular['items'][0]['extras'] = [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]];
        $regular['items'][0]['option_ids'] = [$option->id];
        [$order] = app(OrderService::class)->create($regular, $seller);
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $canonical($item);
                }
            }

            return $value;
        };
        $order->update(['request_hash' => hash('sha256', json_encode($canonical($legacy), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))]);
        $headers = $this->tokenFor($seller);
        $this->postJson('/api/v1/orders', $legacy, $headers)->assertOk()->assertJsonPath('data.number', $order->id);
        $legacy['uuid'] = (string) Str::uuid();
        $legacy['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $legacy, $headers)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_only_admin_can_modify_recipe_and_csrf_is_required(): void
    {
        [, $product] = $this->fixture();
        $url = '/admin/catalogo/products/'.$product->id.'/composicion';
        $data = ['revision' => 0, 'name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => 1, 'is_active' => 1];
        $this->actingAs($this->accountFor(Role::Seller))->post($url, $data)->assertForbidden();
        $this->actingAs($this->accountFor(Role::Administrator));
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post($url, $data)->assertStatus(419);
        $this->assertDatabaseCount('catalog_combo_components', 0);
    }
}
