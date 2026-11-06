<?php

use App\Models\User;
use App\Services\KitchenService;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/bootstrap.php';

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
    [$order, $replayed] = app(KitchenService::class)->transition($data['uuid'], $data['expected'], $data['status'], $data['operation'], User::findOrFail((int) $userId));
    $result = ['http' => 200, 'replayed' => $replayed, 'status' => $order->kitchen_status];
} catch (HttpException $e) {
    $result = ['http' => $e->getStatusCode()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
