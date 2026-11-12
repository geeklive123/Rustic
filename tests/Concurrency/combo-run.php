<?php

require __DIR__.'/combo-bootstrap.php';

use App\Enums\Role;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function comboRace(array $jobs, array $actors): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rustik-combo-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($jobs as $slot => $job) {
            $p = new Process([PHP_BINARY, __DIR__.'/combo-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR)), (string) $actors[$slot], $barrier, (string) $slot]);
            $p->setTimeout(40);
            $p->start();
            $processes[] = $p;
        }
        $deadline = microtime(true) + 20;
        while (! is_file($barrier.'.0.ready') || ! is_file($barrier.'.1.ready')) {
            foreach ($processes as $p) {
                if (! $p->isRunning()) {
                    throw new RuntimeException('Worker failed: '.$p->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout');
            }
            usleep(10000);
        }
        file_put_contents($barrier.'.go', 'go');
        $results = [];
        foreach ($processes as $p) {
            $p->wait();
            if (! $p->isSuccessful()) {
                throw new RuntimeException('Worker failed: '.$p->getErrorOutput());
            }
            $results[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $p) {
            if ($p->isRunning()) {
                $p->stop(1);
            }
        }
        foreach (['.0.ready', '.1.ready', '.go'] as $suffix) {
            if (is_file($barrier.$suffix)) {
                unlink($barrier.$suffix);
            }
        }
    }
}

$tag = (string) Str::uuid();
$admin = User::factory()->create(['role' => Role::Administrator, 'is_active' => true]);
$seller = User::factory()->create(['role' => Role::Seller, 'is_active' => true]);
$category = Category::create(['name' => 'combo-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'combo-'.$tag, 'category_id' => $category->id, 'price_cents' => 4000, 'is_active' => true]);
$extra = Extra::create(['name' => 'combo-extra-'.$tag, 'price_cents' => 200, 'is_active' => true]);
$product->extras()->attach($extra);
$component = $product->comboComponents()->create(['name' => 'Hamburguesa', 'quantity' => 2, 'customizable' => true, 'is_active' => true]);
$component->extras()->attach($extra);
$part = ['component_id' => $component->id, 'extras' => [['extra_id' => $extra->id, 'expected_unit_price_cents' => 200]], 'option_ids' => []];
$body = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'combo-race', 'customer_name' => 'Test', 'payment_method' => 'CASH', 'expected_total_cents' => 4400,
    'items' => [['product_id' => $product->id, 'combo_revision' => 0, 'quantity' => 1, 'expected_unit_price_cents' => 4000, 'extras' => [], 'option_ids' => [], 'components' => [$part, $part]]]];
$job = ['type' => 'sale', 'body' => $body];
$result = comboRace([$job, $job], [$seller->id, $seller->id]);
$created = array_column($result, 'created');
sort($created);
if (array_column($result, 'status') !== [200, 200] || $created !== [false, true] || $result[0]['id'] !== $result[1]['id'] || Payment::where('order_id', $result[0]['id'])->count() !== 1) {
    throw new RuntimeException('Concurrent replay was not idempotent.');
}
$body['uuid'] = (string) Str::uuid();
$body['operation_id'] = (string) Str::uuid();
$sale = ['type' => 'sale', 'body' => $body];
$edit = ['type' => 'edit', 'product' => $product->id, 'component' => $component->id,
    'body' => ['revision' => 0, 'name' => 'Nueva receta', 'quantity' => 2, 'customizable' => 1, 'is_active' => 1, 'extra_ids' => [$extra->id], 'option_ids' => []]];
$result = comboRace([$sale, $edit], [$seller->id, $admin->id]);
if ($result[1]['status'] !== 200 || ! in_array($result[0]['status'], [200, 409], true) || (int) $product->fresh()->combo_revision !== 1) {
    throw new RuntimeException('Recipe edit / sale race failed.');
}
if ($result[0]['status'] === 200) {
    $saved = Order::with('items.components')->findOrFail($result[0]['id']);
    if ($saved->items->first()->components->first()->name !== 'Hamburguesa 1' || $saved->total_cents !== 4400) {
        throw new RuntimeException('Sale captured an inconsistent recipe.');
    }
}
echo "Concurrent replay: one sale and one payment. Recipe edit versus sale: sale={$result[0]['status']}, edit=200; revision and snapshot consistent.\n";
