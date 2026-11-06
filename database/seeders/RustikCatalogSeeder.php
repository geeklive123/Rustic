<?php

namespace Database\Seeders;

use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RustikCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('El catalogo de demostracion solo se permite en local.');
        }
        DB::transaction(function (): void {
            $categories = [];
            foreach (['Hamburguesas', 'Combos', 'Especiales', 'Complementos'] as $name) {
                $categories[$name] = Category::firstOrCreate(['name' => $name], ['is_active' => true]);
            }
            $extras = [];
            foreach (['Queso extra' => 200, 'Huevo extra' => 200, 'Tocino extra' => 300] as $name => $price) {
                $extras[] = Extra::firstOrCreate(['name' => $name], ['price_cents' => $price, 'is_active' => true])->id;
            }
            foreach (['Con llajua', 'Sin pimentón', 'Sin mostaza'] as $name) {
                CatalogOption::firstOrCreate(['name' => $name], ['is_active' => true]);
            }
            $products = [
                ['Salchiburguer', 'Hamburguesas', 2500], ['Rustik Clásica', 'Hamburguesas', 2000],
                ['La Huérfana', 'Hamburguesas', 1500], ['Hamburguesa Aniversario', 'Hamburguesas', 3000],
                ['Costillitas BBQ', 'Especiales', 3000], ['Combo Especial Pareja', 'Combos', 4000], ['Salchipapa', 'Complementos', 2000],
            ];
            foreach ($products as [$name, $category, $price]) {
                $product = Product::firstOrCreate(['name' => $name], ['category_id' => $categories[$category]->id, 'price_cents' => $price, 'is_active' => true]);
                if (in_array($category, ['Hamburguesas', 'Combos'], true)) {
                    $product->extras()->syncWithoutDetaching($extras);
                }
            }
        });
    }
}
