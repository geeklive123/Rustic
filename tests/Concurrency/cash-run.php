<?php

require __DIR__.'/bootstrap.php';

use App\Enums\Role;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\CashShiftService;
use App\Services\OrderService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

function cashRace(array $payloads, array $users): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rustik-cash-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($payloads as $slot => $payload) {
            $p = new Process([PHP_BINARY, __DIR__.'/cash-worker.php', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), (string) $users[$slot], $barrier, (string) $slot]);
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
                throw new RuntimeException('Barrier timeout.');
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
$users = [User::factory()->create(['role' => Role::Seller, 'is_active' => true]), User::factory()->create(['role' => Role::Seller, 'is_active' => true])];
$category = Category::create(['name' => 'cash-race-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'cash-race-'.$tag, 'category_id' => $category->id, 'is_active' => true, 'price_cents' => 2500]);
$service = app(CashShiftService::class);
$openA = ['type' => 'open', 'body' => ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000]];
$openB = ['type' => 'open', 'body' => ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000]];
$result = cashRace([$openA, $openB], [$users[0]->id, $users[1]->id]);
$codes = array_column($result, 'http');
sort($codes);
if ($codes !== [200, 409] || CashShift::whereNull('closed_at')->count() !== 1) {
    throw new RuntimeException('Concurrent opening did not leave exactly one open shift.');
}
$winner = $result[0]['http'] === 200 ? 0 : 1;
$shift = CashShift::findOrFail($result[$winner]['id']);
$shifts = [$shift, $shift];
$orders = [];
foreach ($shifts as $shift) {
    $orders[] = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'cash-race', 'customer_name' => 'Test',
        'payment_method' => 'CASH', 'cash_shift_id' => $shift->id, 'expected_total_cents' => 2500,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => []]]];
}
$result = cashRace([['type' => 'order', 'body' => $orders[0]], ['type' => 'order', 'body' => $orders[1]]], [$users[0]->id, $users[1]->id]);
if (array_column($result, 'http') !== [200, 200] || $result[0]['id'] === $result[1]['id']) {
    throw new RuntimeException('Two sellers failed.');
}
foreach ($shifts as $shift) {
    if ($service->cashTotal($shift) !== 5000) {
        throw new RuntimeException('Shift payment missing.');
    }
}
$badTotal = $orders[0];
$badTotal['uuid'] = (string) Str::uuid();
$badTotal['operation_id'] = (string) Str::uuid();
$badTotal['expected_total_cents'] = 1;
try {
    app(OrderService::class)->create($badTotal, $users[0]);
    throw new RuntimeException('Invalid total accepted.');
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
}
if (Order::where('uuid', $badTotal['uuid'])->exists() || $service->cashTotal($shifts[0]) !== 5000) {
    throw new RuntimeException('Failed order did not roll back.');
}
$close = ['operation_id' => (string) Str::uuid(), 'counted_cents' => 6000];
$same = ['type' => 'close', 'id' => $shifts[0]->id, 'body' => $close];
$result = cashRace([$same, $same], [$users[$winner]->id, $users[$winner]->id]);
$replays = array_column($result, 'replayed');
sort($replays);
if (array_column($result, 'http') !== [200, 200] || $replays !== [false, true] || array_column($result, 'expected') !== [6000, 6000]) {
    throw new RuntimeException('Concurrent close replay failed.');
}
[$competing] = $service->open($users[0], ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0]);
$a = ['type' => 'close', 'id' => $competing->id, 'body' => ['operation_id' => (string) Str::uuid(), 'counted_cents' => 0]];
$b = $a;
$b['body']['operation_id'] = (string) Str::uuid();
$result = cashRace([$a, $b], [$users[0]->id, $users[0]->id]);
$codes = array_column($result, 'http');
sort($codes);
if ($codes !== [200, 409]) {
    throw new RuntimeException('Conflicting close was not rejected.');
}
$invalid = $orders[0];
$invalid['uuid'] = (string) Str::uuid();
$invalid['operation_id'] = (string) Str::uuid();
$invalid['cash_shift_id'] = $shifts[1]->id;
try {
    app(OrderService::class)->create($invalid, $users[0]);
    throw new RuntimeException('Closed shift was accepted.');
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
}
if (Payment::whereIn('cash_shift_id', array_map(fn ($shift) => $shift->id, $shifts))->count() !== 2) {
    throw new RuntimeException('Duplicate or partial payment.');
}
[$third] = $service->open($users[0], ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 1000]);
$sale = $orders[0];
$sale['uuid'] = (string) Str::uuid();
$sale['operation_id'] = (string) Str::uuid();
$sale['cash_shift_id'] = $third->id;
$closeRace = ['type' => 'close', 'id' => $third->id, 'body' => ['operation_id' => (string) Str::uuid(), 'counted_cents' => 1000, 'note' => 'Conteo concurrente']];
$result = cashRace([['type' => 'order', 'body' => $sale], $closeRace], [$users[0]->id, $users[0]->id]);
$third->refresh();
if ($result[1]['http'] !== 200 || ! in_array($result[0]['http'], [200, 409], true)
    || $third->cash_sales_cents !== ($result[0]['http'] === 200 ? 2500 : 0)
    || Payment::where('cash_shift_id', $third->id)->count() !== ($result[0]['http'] === 200 ? 1 : 0)) {
    throw new RuntimeException('Close/order race lost a payment or failed rollback.');
}
$sameUuid = (string) Str::uuid();
$one = $orders[0];
$one['uuid'] = $sameUuid;
$one['operation_id'] = (string) Str::uuid();
unset($one['cash_shift_id']);
$two = $orders[1];
$two['uuid'] = $sameUuid;
$two['operation_id'] = (string) Str::uuid();
unset($two['cash_shift_id']);
$result = cashRace([['type' => 'order', 'body' => $one], ['type' => 'order', 'body' => $two]], [$users[0]->id, $users[1]->id]);
$codes = array_column($result, 'http');
sort($codes);
$sameOrder = Order::where('uuid', $sameUuid)->firstOrFail();
if ($codes !== [200, 409] || Order::where('uuid', $sameUuid)->count() !== 1 || Payment::where('order_id', $sameOrder->id)->count() !== 1) {
    throw new RuntimeException('Same UUID created a duplicate sale or payment.');
}
echo "Same UUID, different sellers: one accepted, one conflict, one payment.\n";
[$fourth] = $service->open($users[0], ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 0]);
$retrySale = $orders[0];
$retrySale['uuid'] = (string) Str::uuid();
$retrySale['operation_id'] = (string) Str::uuid();
$retrySale['cash_shift_id'] = $fourth->id;
$retry = ['type' => 'order', 'body' => $retrySale];
$result = cashRace([$retry, $retry], [$users[0]->id, $users[0]->id]);
$created = array_column($result, 'created');
sort($created);
if (array_column($result, 'http') !== [200, 200] || $created !== [false, true] || $result[0]['id'] !== $result[1]['id']
    || Payment::where('order_id', $result[0]['id'])->count() !== 1 || $service->cashTotal($fourth) !== 2500) {
    throw new RuntimeException('Concurrent cash retry duplicated an order or payment.');
}
$service->close($users[0], $fourth->id, ['operation_id' => (string) Str::uuid(), 'counted_cents' => 2500]);
echo "Concurrent cash retry: one order, one payment, 2500 cents in the shift.\n";
echo "Cash races passed: two sellers, concurrent close replay, competing closes and transaction integrity.\n";
