<?php

// Run only against the dedicated disposable database described in docs/api-v1.md.
require __DIR__.'/bootstrap.php';
use App\Enums\Role;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function race(array $payloads, int $sellerId): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rustik-race-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($payloads as $slot => $payload) {
            $process = new Process([PHP_BINARY, __DIR__.'/worker.php', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), (string) $sellerId, $barrier, (string) $slot]);
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
                throw new RuntimeException('Workers did not become ready.');
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
$seller = User::factory()->create(['role' => Role::Seller, 'is_active' => true]);
$category = Category::create(['name' => 'race-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'race-'.$tag, 'category_id' => $category->id, 'is_active' => true, 'price_cents' => 2500]);
$payload = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'race-device',
    'customer_name' => 'Race test', 'payment_method' => 'CASH', 'expected_total_cents' => 2500,
    'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => []]]];
$results = race([$payload, $payload], $seller->id);
if ($results[0]['id'] !== $results[1]['id'] || $results[0]['created'] === $results[1]['created'] || Payment::where('order_id', $results[0]['id'])->count() !== 1) {
    throw new RuntimeException('Concurrent replay was not idempotent.');
}
$second = $payload;
$second['uuid'] = (string) Str::uuid();
$second['operation_id'] = (string) Str::uuid();
$second['device_id'] = 'race-device-2';
$third = $payload;
$third['uuid'] = (string) Str::uuid();
$third['operation_id'] = (string) Str::uuid();
$third['device_id'] = 'race-device-3';
$results = race([$second, $third], $seller->id);
if ($results[0]['id'] === $results[1]['id'] || ! $results[0]['created'] || ! $results[1]['created']) {
    throw new RuntimeException('Distinct simultaneous devices did not get unique numbers.');
}
echo "Concurrent replay and two-device numbering passed.\n";
