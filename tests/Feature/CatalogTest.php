<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Product;
use Database\Seeders\RustikCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CatalogFixtures;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    public function test_catalog_returns_active_items_and_prices_for_seller(): void
    {
        [$category, $product, $extra, $option] = $this->fixture();
        Product::create(['name' => 'Inactivo', 'category_id' => $category->id, 'price_cents' => 1, 'is_active' => false]);
        $headers = $this->tokenFor($this->accountFor(Role::Seller));
        $this->getJson('/api/v1/catalog', $headers)->assertOk()->assertJsonPath('data.products.0.price_cents', 2500)
            ->assertJsonPath('data.extras.0.price_cents', 200)->assertJsonCount(1, 'data.products')->assertJsonPath('data.options.0.name', 'Con llajua');
        $this->getJson('/api/v1/categories', $headers)->assertOk()->assertJsonPath('data.0.name', 'Hamburguesas');
    }

    public function test_kitchen_receives_catalog_prices(): void
    {
        [, $product, $extra, $option] = $this->fixture();
        $component = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => true, 'is_active' => true]);
        $component->extras()->attach($extra);
        $component->options()->attach($option);
        foreach ([Role::Kitchen, Role::Seller] as $role) {
            $this->app['auth']->forgetGuards();
            $headers = $this->tokenFor($this->accountFor($role));
            $this->getJson('/api/v1/catalog', $headers)->assertOk()->assertJsonPath('data.products.0.price_cents', 2500)->assertJsonPath('data.extras.0.price_cents', 200)
                ->assertJsonPath('data.categories.0.name', 'Hamburguesas')->assertJsonPath('data.options.0.name', 'Con llajua')
                ->assertJsonPath('data.products.0.combo_components.0.id', $component->id)
                ->assertJsonPath('data.products.0.combo_components.0.extra_ids.0', $extra->id)
                ->assertJsonPath('data.products.0.combo_components.0.option_ids.0', $option->id);
            $this->getJson('/api/v1/catalog', $headers)->assertOk()->assertJsonPath('data.products.0.price_cents', 2500);
            $this->getJson('/api/v1/extras', $headers)->assertOk()->assertJsonPath('data.0.price_cents', 200);
        }
    }

    public function test_only_admin_can_change_catalog_and_negative_price_is_rejected(): void
    {
        [$category, $product] = $this->fixture();
        $seller = $this->tokenFor($this->accountFor(Role::Seller));
        $this->patchJson('/api/v1/admin/catalog/products/'.$product->id, ['price_cents' => 100], $seller)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $admin = $this->tokenFor($this->accountFor(Role::Administrator));
        $this->patchJson('/api/v1/admin/catalog/products/'.$product->id, ['price_cents' => -1], $admin)->assertUnprocessable();
        $this->patchJson('/api/v1/admin/catalog/products/'.$product->id, ['price_cents' => 2600], $admin)->assertOk()->assertJsonPath('data.price_cents', 2600);
        $this->postJson('/api/v1/admin/catalog/products', [], $admin)->assertUnprocessable();
    }

    public function test_demo_seed_is_explicit_local_only_and_idempotent(): void
    {
        $seed = new RustikCatalogSeeder;
        $this->expectException(\RuntimeException::class);
        $seed->run();
    }

    public function test_demo_seed_has_expected_prices_and_extra_restrictions(): void
    {
        $this->app->instance('env', 'local');
        try {
            $seed = new RustikCatalogSeeder;
            $seed->run();
            $seed->run();
            $this->assertDatabaseCount('categories', 4);
            $this->assertDatabaseCount('products', 7);
            $this->assertDatabaseCount('extras', 3);
            $this->assertDatabaseCount('catalog_options', 3);
            $product = Product::where('name', 'Salchiburguer')->firstOrFail();
            $this->assertSame(2500, $product->price_cents);
            $this->assertCount(3, $product->extras);
            $this->assertCount(0, Product::where('name', 'Salchipapa')->firstOrFail()->extras);
            foreach (['Salchiburguer' => 2500, 'Rustik Clásica' => 2000, 'La Huérfana' => 1500, 'Hamburguesa Aniversario' => 3000, 'Costillitas BBQ' => 3000, 'Combo Especial Pareja' => 4000, 'Salchipapa' => 2000] as $name => $price) {
                $this->assertDatabaseHas('products', ['name' => $name, 'price_cents' => $price]);
            }
            foreach (['Queso extra' => 200, 'Huevo extra' => 200, 'Tocino extra' => 300] as $name => $price) {
                $this->assertDatabaseHas('extras', ['name' => $name, 'price_cents' => $price]);
            }
            foreach (['Con llajua', 'Sin pimentón', 'Sin mostaza'] as $name) {
                $this->assertDatabaseHas('catalog_options', ['name' => $name]);
            }
            $product->update(['price_cents' => 2600]);
            $seed->run();
            $this->assertSame(2600, $product->fresh()->price_cents);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_catalog_requires_authentication(): void
    {
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
        $this->getJson('/api/v1/extras', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    }
}
