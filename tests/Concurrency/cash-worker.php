<?php

require __DIR__.'/bootstrap.php';

use App\Models\User;
use App\Services\CashShiftService;
use App\Services\OrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;

[$script, $encoded, $userId, $barrier, $slot] = $argv;
$data = json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($barrier.'.'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout.');
    }
    usleep(10000);
}
try {
    $user = User::findOrFail((int) $userId);
    if ($data['type'] === 'open') {
        [$shift, $replayed] = app(CashShiftService::class)->open($user, $data['body']);
        $result = ['http' => 200, 'replayed' => $replayed, 'id' => $shift->id];
    } elseif ($data['type'] === 'close') {
        [$shift, $replayed] = app(CashShiftService::class)->close($user, $data['id'], $data['body']);
        $result = ['http' => 200, 'replayed' => $replayed, 'expected' => $shift->expected_cents];
    } else {
        [$order, $created] = app(OrderService::class)->create($data['body'], $user);
        $result = ['http' => 200, 'created' => $created, 'id' => $order->id];
    }
} catch (HttpException $e) {
    $result = ['http' => $e->getStatusCode()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
