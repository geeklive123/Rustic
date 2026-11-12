<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CashShift;
use App\Services\CashShiftService;
use App\Services\OrderService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class AdminCashWebTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    }

    public function test_admin_access_and_insecure_configuration_are_blocked(): void
    {
        $this->get('/admin/caja')->assertRedirect('/admin/login');
        $seller = $this->accountFor(Role::Seller);
        $this->actingAs($seller)->get('/admin/caja')->assertForbidden();
        config(['app.key' => null]);
        $this->get('/admin/caja')->assertStatus(503);
    }

    public function test_csrf_is_required_for_admin_posts(): void
    {
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        $this->post('/admin/login', [])->assertStatus(419);
        $this->withSession(['_token' => 'test-token'])->post('/admin/login', ['_token' => 'test-token'])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_open_close_replay_and_read_only_history(): void
    {
        $admin = $this->accountFor(Role::Administrator);
        $this->actingAs($admin);
        $open = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening' => '10.25', 'opening_qr' => '2.00'];
        $this->post('/admin/caja/abrir', $open)->assertRedirect();
        $shift = CashShift::firstOrFail();
        $this->assertSame(1025, $shift->opening_cents);
        $this->post('/admin/caja/abrir', $open)->assertRedirect();
        $this->post('/admin/caja/abrir', array_merge($open, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid()]))->assertStatus(409);
        $close = ['operation_id' => (string) Str::uuid(), 'counted' => '10.00'];
        $this->post('/admin/caja/'.$shift->id.'/cerrar', $close)->assertSessionHasErrors('note');
        $close['note'] = 'Faltante';
        $this->post('/admin/caja/'.$shift->id.'/cerrar', $close)->assertRedirect();
        $this->post('/admin/caja/'.$shift->id.'/cerrar', $close)->assertRedirect();
        $this->assertSame(-25, $shift->fresh()->difference_cents);
        $this->get('/admin/caja/historial')->assertOk()->assertSee('#'.$shift->id);
        $this->get('/admin/caja/'.$shift->id)->assertOk()->assertSee('Faltante')->assertSee('Las ventas de APK que no env?an caja');
        $this->get('/admin/caja/'.$shift->id.'/cerrar')->assertStatus(409);
        $this->get('/admin/caja/'.$shift->id.'/pedidos')->assertOk();
    }

    public function test_login_requires_active_administrator_and_logout_ends_session(): void
    {
        $admin = $this->accountFor(Role::Administrator);
        $admin->update(['password' => 'test-password-123']);
        $seller = $this->accountFor(Role::Seller);
        $seller->update(['password' => 'test-password-123']);
        $this->post('/admin/login', ['email' => $seller->email, 'password' => 'test-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'test-password-123'])->assertRedirect(route('cash.current'));
        $this->assertAuthenticatedAs($admin);
        $this->post('/admin/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_web_orders_show_only_linked_sales_with_full_details(): void
    {
        [$category, $product, $extra, $option] = $this->fixture();
        $admin = $this->accountFor(Role::Administrator);
        $seller = $this->accountFor(Role::Seller);
        $service = app(CashShiftService::class);
        [$shift] = $service->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000]);
        $linked = $this->payload($product, $extra, $option);
        $linked['customer_name'] = 'Cliente vinculado';
        $linked['cash_shift_id'] = $shift->id;
        [$order] = app(OrderService::class)->create($linked, $seller);
        $unlinked = $this->payload($product, $extra, $option);
        $unlinked['customer_name'] = 'Cliente sin turno';
        app(OrderService::class)->create($unlinked, $seller);
        $this->actingAs($admin)->get('/admin/caja/'.$shift->id.'/pedidos')->assertOk()
            ->assertSee('Pedido #'.$order->id)->assertSee('Cliente vinculado')->assertSee($seller->name)
            ->assertSee($product->name)->assertSee($extra->name)->assertSee($option->name)->assertDontSee('Cliente sin turno');
        $this->get('/admin/caja')->assertOk()->assertSee('Ventas en efectivo')->assertSee('QR simulado')
            ->assertSee('QR est?tico verificado')->assertSee('QR pendiente')->assertSee('Pedidos sin pagar')
            ->assertSee('Bs 54.00')->assertSee('Las ventas de APK que no env?an caja');
        $this->get('/admin/caja/'.$shift->id.'/cerrar')->assertOk()->assertSee('Efectivo contado');
    }
}
