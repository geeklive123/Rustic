<?php

require __DIR__.'/bootstrap.php';

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\KitchenService;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function kitchenRace(array $payloads, array $users): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rustik-kitchen-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($payloads as $slot => $payload) {
            $p = new Process([PHP_BINARY, __DIR__.'/kitchen-worker.php', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), (string) $users[$slot], $barrier, (string) $slot]);
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
                throw new RuntimeException('Workers timed out.');
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
$seller = User::factory()->create(['role' => Role::Seller, 'is_active' => true]);
$first = User::factory()->create(['role' => Role::Kitchen, 'is_active' => true]);
$second = User::factory()->create(['role' => Role::Kitchen, 'is_active' => true]);
$category = Category::create(['name' => 'kitchen-race-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'kitchen-race-'.$tag, 'category_id' => $category->id, 'is_active' => true, 'price_cents' => 2500]);
$data = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'kitchen-race', 'customer_name' => 'Race test',
    'payment_method' => 'CASH', 'expected_total_cents' => 2500, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => []]]];
[$order] = app(OrderService::class)->create($data, $seller);
$transition = ['uuid' => $order->uuid, 'expected' => 'NEW', 'status' => 'PREPARING', 'operation' => (string) Str::uuid()];
$other = $transition;
$other['operation'] = (string) Str::uuid();
$results = kitchenRace([$transition, $other], [$first->id, $second->id]);
$codes = array_column($results, 'http');
sort($codes);
if ($codes !== [200, 409] || DB::table('order_status_events')->where('order_id', $order->id)->count() !== 1 || $order->fresh()->kitchen_status !== 'PREPARING') {
    throw new RuntimeException('Concurrent cooks failed.');
}
$next = ['uuid' => $order->uuid, 'expected' => 'PREPARING', 'status' => 'READY', 'operation' => (string) Str::uuid()];
$results = kitchenRace([$next, $next], [$first->id, $first->id]);
$replays = array_column($results, 'replayed');
sort($replays);
if (array_column($results, 'http') !== [200, 200] || $replays !== [false, true] || DB::table('order_status_events')->where('order_id', $order->id)->count() !== 2 || $order->fresh()->kitchen_status !== 'READY') {
    throw new RuntimeException('Concurrent replay failed.');
}
$stale = $next;
$stale['operation'] = (string) Str::uuid();
$results = kitchenRace([$stale, $stale], [$first->id, $first->id]);
if (array_column($results, 'http') !== [409, 409] || DB::table('order_status_events')->where('order_id', $order->id)->count() !== 2 || $order->fresh()->kitchen_status !== 'READY') {
    throw new RuntimeException('Stale transitions changed the order or audit.');
}
$data['uuid'] = (string) Str::uuid();
$data['operation_id'] = (string) Str::uuid();
[$order2] = app(OrderService::class)->create($data, $seller);
$data['uuid'] = (string) Str::uuid();
$data['operation_id'] = (string) Str::uuid();
[$order3] = app(OrderService::class)->create($data, $seller);
$shared = (string) Str::uuid();
$a = ['uuid' => $order2->uuid, 'expected' => 'NEW', 'status' => 'PREPARING', 'operation' => $shared];
$b = ['uuid' => $order3->uuid, 'expected' => 'NEW', 'status' => 'PREPARING', 'operation' => $shared];
$results = kitchenRace([$a, $b], [$first->id, $first->id]);
$codes = array_column($results, 'http');
sort($codes);
$statuses = [$order2->fresh()->kitchen_status, $order3->fresh()->kitchen_status];
sort($statuses);
if ($codes !== [200, 409] || $statuses !== ['NEW', 'PREPARING'] || DB::table('order_status_events')->where('operation_id', $shared)->count() !== 1) {
    throw new RuntimeException('Conflicting audit operation did not roll back the order.');
}
$data['uuid'] = (string) Str::uuid();
$data['operation_id'] = (string) Str::uuid();
[$order4] = app(OrderService::class)->create($data, $seller);
app(KitchenService::class)->transition($order4->uuid, 'NEW', 'PREPARING', (string) Str::uuid(), $first);
$ready = ['uuid' => $order4->uuid, 'expected' => 'PREPARING', 'status' => 'READY', 'operation' => (string) Str::uuid()];
$deliver = ['uuid' => $order4->uuid, 'expected' => 'READY', 'status' => 'DELIVERED', 'operation' => (string) Str::uuid()];
$results = kitchenRace([$ready, $deliver], [$first->id, $seller->id]);
$final = $order4->fresh()->kitchen_status;
if ($results[0]['http'] !== 200 || ! in_array($results[1]['http'], [200, 409], true)
    || $final !== ($results[1]['http'] === 200 ? 'DELIVERED' : 'READY')
    || DB::table('order_status_events')->where('order_id', $order4->id)->count() !== ($results[1]['http'] === 200 ? 3 : 2)) {
    throw new RuntimeException('Kitchen and seller race was inconsistent.');
}
echo 'Seller delivery versus kitchen ready: kitchen='.$results[0]['http'].', seller='.$results[1]['http'].', final='.$final."\n";
echo "Kitchen races passed: conflicting cooks, concurrent replay, stale transitions, audit uniqueness and rollback.\n";
