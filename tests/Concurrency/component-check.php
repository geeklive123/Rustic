<?php

require __DIR__.'/combo-bootstrap.php';

use App\Enums\Role;
use App\Models\CatalogOption;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Str;

$tag = (string) Str::uuid();
$seller = User::factory()->create(['role' => Role::Seller, 'is_active' => true]);
$category = Category::create(['name' => 'component-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'component-'.$tag, 'category_id' => $category->id, 'price_cents' => 4000, 'is_active' => true]);
$extra = Extra::create(['name' => 'component-extra-'.$tag, 'price_cents' => 200, 'is_active' => true]);
$product->extras()->attach($extra);
$option = CatalogOption::create(['name' => 'component-option-'.$tag, 'is_active' => true]);
$definition = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => true, 'is_active' => true]);
$definition->extras()->attach($extra);
$definition->options()->attach($option);
$body = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'component-test', 'customer_name' => 'Test', 'payment_method' => 'CASH', 'expected_total_cents' => 4400,
    'items' => [['product_id' => $product->id, 'combo_revision' => 0, 'quantity' => 1, 'expected_unit_price_cents' => 4000, 'extras' => [], 'option_ids' => [],
        'components' => [
            ['component_id' => $definition->id, 'notes' => 'Con tomate', 'option_ids' => [$option->id], 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]]],
            ['component_id' => $definition->id, 'notes' => 'Sin tomate', 'option_ids' => [], 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]]],
        ]]]];
[$order, $created] = app(OrderService::class)->create($body, $seller);
[$replay, $createdAgain] = app(OrderService::class)->create($body, $seller);
$item = $order->items->first();
if (! $created || $createdAgain || $replay->id !== $order->id || $order->total_cents !== 4400 || $item->components->count() !== 2 || $item->components->sum(fn ($c) => $c->extras->count()) !== 2 || ! str_contains($item->kitchenNotes(), 'Sin tomate')) {
    throw new RuntimeException('Component snapshots, prices or replay failed.');
}
echo "MariaDB components passed: base + two extras = 4400, two snapshots, kitchen notes and idempotent replay.\n";
