<?php

require __DIR__.'/bootstrap.php';

use App\Enums\Role;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\SpecialPriceAuthorizations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function priceRace(array $bodies, int $seller): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rustik-price-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($bodies as $slot => $body) {
            $process = new Process([PHP_BINARY, __DIR__.'/special-price-worker.php', base64_encode(json_encode($body, JSON_THROW_ON_ERROR)), (string) $seller, $barrier, (string) $slot]);
            $process->setTimeout(40);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (! is_file($barrier.'.0.ready') || ! is_file($barrier.'.1.ready')) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Worker failed: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout');
            }
            usleep(10000);
        }
        file_put_contents($barrier.'.go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Worker failed: '.$process->getErrorOutput());
            }
            $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
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
$category = Category::create(['name' => 'price-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'price-'.$tag, 'category_id' => $category->id, 'price_cents' => 2500, 'is_active' => true]);
$body = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'price-race', 'customer_name' => 'Test', 'payment_method' => 'CASH', 'expected_total_cents' => 2000,
    'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2000, 'extras' => [], 'option_ids' => []]]];
$authorization = app(SpecialPriceAuthorizations::class)->issue($admin, ['operation_id' => (string) Str::uuid(), 'order_uuid' => $body['uuid'], 'product_id' => $product->id, 'seller_id' => $seller->id, 'quantity' => 1, 'authorized_cents' => 2000, 'reason' => 'Prueba concurrente']);
$body['items'][0]['price_authorization_id'] = $authorization->id;
$results = priceRace([$body, $body], $seller->id);
$created = array_column($results, 'created');
sort($created);
if (array_column($results, 'status') !== [200, 200] || $created !== [false, true] || $results[0]['id'] !== $results[1]['id'] || Payment::where('order_id', $results[0]['id'])->count() !== 1
    || (int) DB::table('special_price_authorizations')->where('id', $authorization->id)->value('order_id') !== $results[0]['id']) {
    throw new RuntimeException('Concurrent authorization replay failed.');
}
$other = $body;
$other['uuid'] = (string) Str::uuid();
$other['operation_id'] = (string) Str::uuid();
$results = priceRace([$body, $other], $seller->id);
if ($results[0]['status'] !== 200 || $results[0]['created'] !== false || $results[1]['status'] !== 409 || Payment::where('order_id', $results[0]['id'])->count() !== 1) {
    throw new RuntimeException('Authorization reuse was not rejected.');
}
echo "Concurrent replay: one sale and payment; authorization reuse rejected.\n";
