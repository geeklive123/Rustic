<?php

require __DIR__.'/bootstrap.php';

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function raceCorrections(array $bodies, int $actor): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rustik-order-edit-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($bodies as $slot => $body) {
            $p = new Process([PHP_BINARY, __DIR__.'/order-correction-worker.php', base64_encode(json_encode($body, JSON_THROW_ON_ERROR)), (string) $actor, $barrier, (string) $slot]);
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
        $result = [];
        foreach ($processes as $p) {
            $p->wait();
            if (! $p->isSuccessful()) {
                throw new RuntimeException('Worker failed: '.$p->getErrorOutput());
            }
            $result[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
        }

        return $result;
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
$category = Category::create(['name' => 'order-edit-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'order-edit-'.$tag, 'category_id' => $category->id, 'price_cents' => 2500, 'is_active' => true]);
$body = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'order-edit-test', 'customer_name' => 'Test', 'payment_method' => 'CASH', 'expected_total_cents' => 2500, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => []]]];
[$order] = app(OrderService::class)->create($body, $seller);
$note = ['type' => 'note', 'id' => $order->id, 'data' => ['operation_id' => (string) Str::uuid(), 'expected_status' => 'NEW', 'expected_note_hash' => hash('sha256', ''), 'notes' => 'Nota A', 'reason' => 'Prueba']];
$other = $note;
$other['data']['operation_id'] = (string) Str::uuid();
$other['data']['notes'] = 'Nota B';
$codes = raceCorrections([$note, $other], $admin->id);
sort($codes);
if ($codes !== [200, 409] || DB::table('order_admin_corrections')->where('order_id', $order->id)->count() !== 1 || $order->fresh()->total_cents !== 2500) {
    throw new RuntimeException('Concurrent corrections were not serialized.');
}
$body['uuid'] = (string) Str::uuid();
$body['operation_id'] = (string) Str::uuid();
[$second] = app(OrderService::class)->create($body, $seller);
$note['id'] = $second->id;
$note['data']['operation_id'] = (string) Str::uuid();
$transition = ['type' => 'transition', 'uuid' => $second->uuid, 'operation' => (string) Str::uuid()];
$result = raceCorrections([$note, $transition], $admin->id);
if (! in_array($result[0], [200, 409], true) || $result[1] !== 200 || $second->fresh()->kitchen_status !== 'PREPARING' || DB::table('order_admin_corrections')->where('order_id', $second->id)->count() !== ($result[0] === 200 ? 1 : 0)) {
    throw new RuntimeException('Correction and kitchen transition were not serialized.');
}
echo "Concurrent notes: one success and one stale conflict; note versus kitchen: note={$result[0]}, transition={$result[1]}.\n";
