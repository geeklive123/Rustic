<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    }

    public function test_catalog_routes_require_admin_and_csrf(): void
    {
        $this->get('/admin/catalogo/products')->assertRedirect('/admin/login');
        $this->actingAs($this->accountFor(Role::Seller))->get('/admin/catalogo/products')->assertForbidden();
        $this->post('/admin/catalogo/categories', [])->assertForbidden();
        $this->actingAs($this->accountFor(Role::Administrator));
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/admin/catalogo/categories', [])->assertStatus(419);
    }

    public function test_create_update_and_replay_all_catalog_types(): void
    {
        $this->actingAs($this->accountFor(Role::Administrator));
        foreach (['categories', 'products', 'extras', 'options'] as $type) {
            $this->get('/admin/catalogo/'.$type.'/nuevo')->assertOk();
        }
        $cat = ['operation_id' => (string) Str::uuid(), 'name' => 'Bebidas', 'is_active' => '1'];
        $this->post('/admin/catalogo/categories', $cat)->assertRedirect();
        $this->post('/admin/catalogo/categories', $cat)->assertRedirect();
        $this->assertDatabaseCount('categories', 1);
        $this->post('/admin/catalogo/categories', array_merge($cat, ['name' => 'Otro']))->assertStatus(409);
        $category = Category::firstOrFail();
        $extra = ['operation_id' => (string) Str::uuid(), 'name' => 'Salsa', 'price' => '1.25', 'is_active' => '1'];
        $this->post('/admin/catalogo/extras', $extra)->assertRedirect();
        $extraId = Extra::firstOrFail()->id;
        $option = ['operation_id' => (string) Str::uuid(), 'name' => 'Sin hielo', 'is_active' => '1'];
        $this->post('/admin/catalogo/options', $option)->assertRedirect();
        $this->post('/admin/catalogo/products', ['operation_id' => (string) Str::uuid(), 'name' => 'Refresco', 'category_id' => $category->id, 'price' => '12.50', 'extra_ids' => [$extraId], 'is_active' => '1'])->assertRedirect();
        $product = Product::firstOrFail();
        $this->assertSame(1250, $product->price_cents);
        $this->assertTrue($product->extras->contains($extraId));
        $this->get('/admin/catalogo/products?search=Refresco&category='.$category->id.'&status=active')->assertOk()->assertSee('Bs 12.50');
        $this->get('/admin/catalogo/categories')->assertOk()->assertSee('Refresco');
        $this->post('/admin/catalogo/categories/'.$category->id, ['operation_id' => (string) Str::uuid(), 'name' => 'Bebidas fr?as', 'is_active' => '0'])->assertRedirect();
        $this->post('/admin/catalogo/options/'.CatalogOption::firstOrFail()->id, ['operation_id' => (string) Str::uuid(), 'name' => 'Con hielo', 'is_active' => '0'])->assertRedirect();
        $this->post('/admin/catalogo/extras/'.$extraId, ['operation_id' => (string) Str::uuid(), 'name' => 'Salsa nueva', 'price' => '1.25', 'expected_price_cents' => 125, 'is_active' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->is_active);
        $this->assertDatabaseHas('catalog_options', ['name' => 'Con hielo', 'is_active' => 0]);
        $this->assertDatabaseHas('extras', ['name' => 'Salsa nueva', 'is_active' => 0]);
        $this->delete('/admin/catalogo/categories/'.$category->id)->assertStatus(405);
    }

    public function test_price_confirmation_audit_history_and_android_conflict(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $admin = $this->accountFor(Role::Administrator);
        $oldPayload = $this->payload($product, $extra, $option);
        [$oldOrder] = app(OrderService::class)->create($oldPayload, $seller);
        $this->actingAs($admin);
        $body = ['operation_id' => (string) Str::uuid(), 'name' => $product->name, 'category_id' => $product->category_id, 'price' => '30.00', 'expected_price_cents' => 2500, 'is_active' => '1', 'extra_ids' => [$extra->id]];
        $this->post('/admin/catalogo/products/'.$product->id, $body)->assertSessionHasErrors('confirm_price');
        $this->assertSame(2500, $product->fresh()->price_cents);
        $body['confirm_price'] = '1';
        $this->post('/admin/catalogo/products/'.$product->id, $body)->assertRedirect();
        $this->post('/admin/catalogo/products/'.$product->id, $body)->assertRedirect();
        $this->assertDatabaseCount('catalog_price_changes', 1);
        $this->assertDatabaseHas('catalog_price_changes', ['item_type' => 'products', 'item_id' => $product->id, 'user_id' => $admin->id, 'old_cents' => 2500, 'new_cents' => 3000]);
        $this->assertSame(5400, $oldOrder->fresh()->total_cents);
        $this->assertSame(2500, $oldOrder->items()->first()->unit_price_cents);
        $stale = $body;
        $stale['operation_id'] = (string) Str::uuid();
        $this->post('/admin/catalogo/products/'.$product->id, $stale)->assertStatus(409);
        $this->get('/admin/catalogo/products/'.$product->id.'/editar')->assertOk()->assertSee('30.00')->assertSee($admin->name);
        $this->app['auth']->forgetGuards();
        $headers = $this->tokenFor($seller);
        $this->getJson('/api/v1/catalog', $headers)->assertOk()->assertJsonPath('data.products.0.price_cents', 3000);
        $newPayload = $this->payload($product, $extra, $option);
        $this->postJson('/api/v1/orders', $newPayload, $headers)->assertStatus(409);
    }

    public function test_api_price_change_is_audited_without_changing_contract(): void
    {
        [, $product] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $headers = $this->tokenFor($admin);
        $this->patchJson('/api/v1/admin/catalog/products/'.$product->id, ['price_cents' => 2700], $headers)->assertOk()->assertJsonPath('data.price_cents', 2700);
        $this->assertDatabaseHas('catalog_price_changes', ['item_type' => 'products', 'item_id' => $product->id, 'user_id' => $admin->id, 'old_cents' => 2500, 'new_cents' => 2700]);
    }

    public function test_extra_price_change_is_audited_and_does_not_change_order_snapshots(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $seller = $this->accountFor(Role::Seller);
        $admin = $this->accountFor(Role::Administrator);
        [$order] = app(OrderService::class)->create($this->payload($product, $extra, $option), $seller);
        $body = ['operation_id' => (string) Str::uuid(), 'name' => $extra->name, 'price' => '3.25', 'expected_price_cents' => 200, 'confirm_price' => '1', 'is_active' => '1'];
        $this->actingAs($admin)->post('/admin/catalogo/extras/'.$extra->id, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('catalog_price_changes', ['item_type' => 'extras', 'item_id' => $extra->id, 'user_id' => $admin->id, 'old_cents' => 200, 'new_cents' => 325]);
        $this->assertSame(200, $order->items()->first()->extras()->first()->unit_price_cents);
        $this->assertSame(5400, $order->fresh()->total_cents);
    }
}
