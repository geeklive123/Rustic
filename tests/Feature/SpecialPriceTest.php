<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Services\SpecialPriceAuthorizations;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class SpecialPriceTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    }

    private function authorization($admin, $seller, $product, array $body, int $price = 2000): object
    {
        return app(SpecialPriceAuthorizations::class)->issue($admin, ['operation_id' => (string) Str::uuid(), 'order_uuid' => $body['uuid'],
            'product_id' => $product->id, 'seller_id' => $seller->id, 'quantity' => $body['items'][0]['quantity'], 'authorized_cents' => $price, 'reason' => 'Precio excepcional']);
    }

    public function test_occasional_product_reuses_catalog_and_legacy_android(): void
    {
        $admin = $this->accountFor(Role::Administrator);
        $category = Category::create(['name' => 'Bebidas', 'is_active' => true]);
        $this->actingAs($admin)->post('/admin/catalogo/products', ['operation_id' => (string) Str::uuid(), 'name' => 'Caf? ocasional', 'category_id' => $category->id, 'price' => '8.50', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::where('name', 'Caf? ocasional')->firstOrFail();
        $this->assertSame(850, $product->price_cents);
        $this->app['auth']->forgetGuards();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $this->getJson('/api/v1/catalog', $seller)->assertOk()->assertJsonPath('data.products.0.name', 'Caf? ocasional')->assertJsonPath('data.products.0.price_cents', 850);
    }

    public function test_authorized_price_is_bound_audited_consumed_once_and_replay_safe(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $body = $this->payload($product, $extra, $option);
        $authorization = $this->authorization($admin, $seller, $product, $body);
        $body['items'][0]['price_authorization_id'] = $authorization->id;
        $body['items'][0]['expected_unit_price_cents'] = 2000;
        $body['expected_total_cents'] = 4400;
        $headers = $this->tokenFor($seller);
        $result = $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.total_cents', 4400)
            ->assertJsonPath('data.items.0.unit_price_cents', 2000)->assertJsonPath('data.items.0.extras.0.unit_price_cents', 200);
        $this->postJson('/api/v1/orders', $body, $headers)->assertOk()->assertJsonPath('data.number', $result->json('data.number'));
        $otherOperation = $body;
        $otherOperation['operation_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $otherOperation, $headers)->assertStatus(409);
        $changed = $body;
        $changed['customer_name'] = 'Otro cliente';
        $this->postJson('/api/v1/orders', $changed, $headers)->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('special_price_authorizations', ['id' => $authorization->id, 'authorized_by' => $admin->id, 'seller_id' => $seller->id,
            'normal_cents' => 2500, 'authorized_cents' => 2000, 'order_id' => $result->json('data.number'), 'reason' => 'Precio excepcional']);
        $this->assertNotNull(DB::table('special_price_authorizations')->where('id', $authorization->id)->value('order_item_id'));
        $this->assertSame(2500, $product->fresh()->price_cents);
        $product->update(['price_cents' => 3000]);
        $this->getJson('/api/v1/orders/'.$body['uuid'], $headers)->assertOk()->assertJsonPath('data.items.0.unit_price_cents', 2000)->assertJsonPath('data.total_cents', 4400);
        $this->postJson('/api/v1/orders', $body, $headers)->assertOk();
    }

    public function test_unauthorized_or_stale_authorizations_never_create_orders(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $other = $this->accountFor(Role::Kitchen);
        $body = $this->payload($product, $extra, $option);
        $authorization = $this->authorization($admin, $seller, $product, $body);
        $body['items'][0]['price_authorization_id'] = $authorization->id;
        $body['items'][0]['expected_unit_price_cents'] = 2000;
        $body['expected_total_cents'] = 4400;
        $otherHeaders = $this->tokenFor($other);
        $this->postJson('/api/v1/orders', $body, $otherHeaders)->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $sellerHeaders = $this->tokenFor($seller);
        $wrong = $body;
        $wrong['uuid'] = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $wrong, $sellerHeaders)->assertStatus(409);
        $wrong = $body;
        $wrong['items'][0]['quantity'] = 1;
        $this->postJson('/api/v1/orders', $wrong, $sellerHeaders)->assertStatus(409);
        $otherProduct = Product::create(['name' => 'Otro producto', 'category_id' => $product->category_id, 'price_cents' => 2500, 'is_active' => true]);
        $wrong = $body;
        $wrong['items'][0]['product_id'] = $otherProduct->id;
        $this->postJson('/api/v1/orders', $wrong, $sellerHeaders)->assertStatus(409);
        $wrong = $body;
        $wrong['items'][0]['expected_unit_price_cents'] = 1;
        $this->postJson('/api/v1/orders', $wrong, $sellerHeaders)->assertStatus(409);
        $wrong = $body;
        unset($wrong['items'][0]['price_authorization_id']);
        $this->postJson('/api/v1/orders', $wrong, $sellerHeaders)->assertStatus(409);
        DB::table('special_price_authorizations')->where('id', $authorization->id)->update(['expires_at' => now('UTC')->subSecond()]);
        $this->postJson('/api/v1/orders', $body, $sellerHeaders)->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertNull(DB::table('special_price_authorizations')->where('id', $authorization->id)->value('used_at'));
    }

    public function test_failed_total_rolls_back_consumption_and_retry_succeeds(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $body = $this->payload($product, $extra, $option);
        $authorization = $this->authorization($admin, $seller, $product, $body);
        $body['items'][0]['price_authorization_id'] = $authorization->id;
        $body['items'][0]['expected_unit_price_cents'] = 2000;
        $body['expected_total_cents'] = 1;
        $headers = $this->tokenFor($seller);
        $this->postJson('/api/v1/orders', $body, $headers)->assertStatus(409);
        $this->assertNull(DB::table('special_price_authorizations')->where('id', $authorization->id)->value('used_at'));
        $body['expected_total_cents'] = 4400;
        $this->postJson('/api/v1/orders', $body, $headers)->assertCreated();
        $body['operation_id'] = (string) Str::uuid();
        $body['uuid'] = (string) Str::uuid();
        $this->postJson('/api/v1/orders', $body, $headers)->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_kitchen_combo_extras_cash_shift_and_qr_are_preserved(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $kitchen = $this->accountFor(Role::Kitchen);
        $definition = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => true, 'is_active' => true]);
        $definition->extras()->attach($extra);
        $definition->options()->attach($option);
        $adminHeaders = $this->tokenFor($admin);
        $shift = $this->postJson('/api/v1/cash-shifts/open', ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0], $adminHeaders)->assertCreated()->json('data.id');
        $body = $this->payload($product, $extra, $option);
        $body['payment_method'] = 'QR_SIMULATED';
        $body['cash_shift_id'] = $shift;
        $body['items'][0] = ['product_id' => $product->id, 'combo_revision' => 0, 'quantity' => 1, 'expected_unit_price_cents' => 2000, 'extras' => [], 'option_ids' => [],
            'components' => [['component_id' => $definition->id, 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]], 'option_ids' => [$option->id]],
                ['component_id' => $definition->id, 'extras' => [], 'option_ids' => []]]];
        $body['expected_total_cents'] = 2200;
        $authorization = $this->authorization($admin, $kitchen, $product, $body);
        $body['items'][0]['price_authorization_id'] = $authorization->id;
        $this->app['auth']->forgetGuards();
        $headers = $this->tokenFor($kitchen);
        $this->postJson('/api/v1/orders', $body, $headers)->assertCreated()->assertJsonPath('data.total_cents', 2200)->assertJsonPath('data.payment.status', 'simulated');
        $this->postJson('/api/v1/orders', $body, $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cash-shifts/'.$shift, $adminHeaders)->assertOk()->assertJsonPath('summary.methods_cents.QR_SIMULATED', 2200)->assertJsonPath('summary.order_count', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_web_authorization_needs_admin_csrf_confirmation_and_is_idempotent(): void
    {
        [, $product] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $body = ['operation_id' => (string) Str::uuid(), 'order_uuid' => (string) Str::uuid(), 'product_id' => $product->id, 'seller_id' => $seller->id,
            'quantity' => 1, 'price' => '20.00', 'reason' => 'Autorizaci?n local', 'confirm' => 1];
        $this->get('/admin/precios-especiales')->assertRedirect('/admin/login');
        $this->actingAs($seller)->post('/admin/precios-especiales', $body)->assertForbidden();
        $this->actingAs($admin);
        $withoutReason = $body;
        $withoutReason['reason'] = '';
        $this->post('/admin/precios-especiales', $withoutReason)->assertSessionHasErrors('reason');
        $this->post('/admin/precios-especiales', $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/admin/precios-especiales', $body)->assertRedirect();
        $this->assertDatabaseCount('special_price_authorizations', 1);
        $different = $body;
        $different['price'] = '19.00';
        $this->post('/admin/precios-especiales', $different)->assertStatus(409);
        $this->get('/admin/precios-especiales')->assertOk()->assertSee('Autorizaci?n local');
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/admin/precios-especiales', $body)->assertStatus(419);
    }
}
