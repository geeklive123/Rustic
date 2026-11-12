<?php

require __DIR__.'/bootstrap.php';

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CashShiftService;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function movementRace(array $jobs, int $actor): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rustik-movement-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($jobs as $slot => $job) {
            $process = new Process([PHP_BINARY, __DIR__.'/cash-movement-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR)), (string) $actor, $barrier, (string) $slot]);
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

$admin = User::factory()->create(['role' => Role::Administrator, 'is_active' => true]);
[$shift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 10000]);
$movement = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'type' => 'OUT', 'amount_cents' => 7000, 'reason' => 'Prueba'];
$job = ['type' => 'movement', 'shift' => $shift->id, 'data' => $movement];
$results = movementRace([$job, $job], $admin->id);
$replays = array_column($results, 'replayed');
sort($replays);
if (array_column($results, 'status') !== [200, 200] || $replays !== [false, true] || DB::table('cash_movements')->where('cash_shift_id', $shift->id)->count() !== 1) {
    throw new RuntimeException('Movement replay race failed.');
}
$movement['uuid'] = (string) Str::uuid();
$movement['operation_id'] = (string) Str::uuid();
$movement['amount_cents'] = 2000;
$job['data'] = $movement;
$competing = $job;
$competing['data']['uuid'] = (string) Str::uuid();
$competing['data']['operation_id'] = (string) Str::uuid();
$results = movementRace([$job, $competing], $admin->id);
$statuses = array_column($results, 'status');
sort($statuses);
if ($statuses !== [200, 409] || DB::table('cash_movements')->where('cash_shift_id', $shift->id)->count() !== 2) {
    throw new RuntimeException('Concurrent withdrawals exceeded available cash.');
}
$movement['uuid'] = (string) Str::uuid();
$movement['operation_id'] = (string) Str::uuid();
$movement['type'] = 'IN';
$movement['amount_cents'] = 1000;
$job['data'] = $movement;
$close = ['type' => 'close', 'shift' => $shift->id, 'data' => ['operation_id' => (string) Str::uuid(), 'counted_cents' => 1000, 'note' => 'Prueba']];
$results = movementRace([$job, $close], $admin->id);
$shift->refresh();
$expected = $results[0]['status'] === 200 ? 2000 : 1000;
if (! in_array($results[0]['status'], [200, 409], true) || $results[1]['status'] !== 200 || $shift->expected_cents !== $expected) {
    throw new RuntimeException('Movement versus close race failed.');
}
$snapshot = $shift->close_snapshot;
$originalId = DB::table('cash_movements')->where('cash_shift_id', $shift->id)->where('type', 'OUT')->orderBy('id')->value('id');
$adjustment = ['type' => 'compensation', 'shift' => $shift->id, 'data' => ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'original_movement_id' => $originalId, 'reason' => 'Correccion']];
$results = movementRace([$adjustment, $adjustment], $admin->id);
$replays = array_column($results, 'replayed');
sort($replays);
if (array_column($results, 'status') !== [200, 200] || $replays !== [false, true] || DB::table('cash_movements')->where('original_movement_id', $originalId)->count() !== 1 || $shift->fresh()->close_snapshot !== $snapshot) {
    throw new RuntimeException('Compensation replay or snapshot race failed.');
}
$otherId = DB::table('cash_movements')->where('cash_shift_id', $shift->id)->where('type', 'OUT')->where('id', '<>', $originalId)->value('id');
$adjustment['data'] = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'original_movement_id' => $otherId, 'reason' => 'Correccion'];
$other = $adjustment;
$other['data']['uuid'] = (string) Str::uuid();
$other['data']['operation_id'] = (string) Str::uuid();
$results = movementRace([$adjustment, $other], $admin->id);
$statuses = array_column($results, 'status');
sort($statuses);
if ($statuses !== [200, 409] || DB::table('cash_movements')->where('original_movement_id', $otherId)->count() !== 1 || $shift->fresh()->close_snapshot !== $snapshot) {
    throw new RuntimeException('Competing compensations were not serialized.');
}
[$newShift] = app(CashShiftService::class)->open($admin, ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0]);
$category = Category::create(['name' => 'race-'.Str::uuid(), 'is_active' => true]);
$product = Product::create(['name' => 'race-'.Str::uuid(), 'category_id' => $category->id, 'price_cents' => 2000, 'is_active' => true]);
$seller = User::factory()->create(['role' => Role::Seller, 'is_active' => true]);
$body = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'race', 'customer_name' => 'Prueba',
    'payment_method' => 'QR_SIMULATED', 'expected_total_cents' => 2000, 'cash_shift_id' => $newShift->id,
    'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2000, 'extras' => [], 'option_ids' => []]]];
[$order] = app(OrderService::class)->create($body, $seller);
$correction = ['type' => 'payment_correction', 'order' => $order->id, 'data' => ['operation_id' => (string) Str::uuid(), 'reason' => 'Prueba', 'evidence' => 'Efectivo contado']];
$results = movementRace([$correction, $correction], $admin->id);
if (array_column($results, 'status') !== [200, 200] || DB::table('order_admin_corrections')->where('order_id', $order->id)->where('action', 'payment_reclassification')->count() !== 1) {
    throw new RuntimeException('Payment correction replay race failed.');
}
$body['uuid'] = (string) Str::uuid();
$body['operation_id'] = (string) Str::uuid();
[$order] = app(OrderService::class)->create($body, $seller);
$correction = ['type' => 'payment_correction', 'order' => $order->id, 'data' => ['operation_id' => (string) Str::uuid(), 'reason' => 'Prueba', 'evidence' => 'Efectivo contado']];
$close = ['type' => 'close', 'shift' => $newShift->id, 'data' => ['operation_id' => (string) Str::uuid(), 'counted_cents' => 0, 'note' => 'Prueba']];
$results = movementRace([$correction, $close], $admin->id);
$newShift->refresh();
$expected = $results[0]['status'] === 200 ? 4000 : 2000;
if (! in_array($results[0]['status'], [200, 409], true) || $results[1]['status'] !== 200 || $newShift->expected_cents !== $expected
    || DB::table('order_admin_corrections')->where('order_id', $order->id)->where('action', 'payment_reclassification')->count() !== ($results[0]['status'] === 200 ? 1 : 0)) {
    throw new RuntimeException('Payment correction versus close race failed.');
}
echo "Movement replay, competing withdrawals, close, compensation and payment correction races passed.\n";
