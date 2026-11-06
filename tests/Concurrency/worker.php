<?php

use App\Models\User;
use App\Services\OrderService;

require __DIR__.'/bootstrap.php';
[$script, $encoded, $sellerId, $barrier, $slot] = $argv;
$data = json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($barrier.'.'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout.');
    }
    usleep(10000);
}
[$order, $created] = app(OrderService::class)->create($data, User::findOrFail((int) $sellerId));
echo json_encode(['created' => $created, 'id' => $order->id, 'uuid' => $order->uuid], JSON_THROW_ON_ERROR);
