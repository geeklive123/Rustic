<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

trait CatalogFixtures
{
    private function accountFor(Role $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function tokenFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function fixture(): array
    {
        $category = Category::create(['name' => 'Hamburguesas', 'is_active' => true]);
        $product = Product::create(['name' => 'Salchiburguer', 'category_id' => $category->id, 'price_cents' => 2500, 'is_active' => true]);
        $extra = Extra::create(['name' => 'Queso extra', 'price_cents' => 200, 'is_active' => true]);
        $product->extras()->attach($extra);
        $option = CatalogOption::create(['name' => 'Con llajua', 'is_active' => true]);

        return [$category, $product, $extra, $option];
    }

    private function payload(Product $product, Extra $extra, CatalogOption $option): array
    {
        return ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'device-test', 'customer_name' => 'Cliente',
            'payment_method' => 'CASH', 'expected_total_cents' => 5400,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_unit_price_cents' => 2500,
                'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]], 'option_ids' => [$option->id]]]];
    }
}
