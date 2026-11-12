<?php

require __DIR__.'/bootstrap.php';

use App\Models\User;
use App\Services\OrderService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$body = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$actor = User::findOrFail((int) $argv[2]);
$barrier = $argv[3];
file_put_contents($barrier.'.'.$argv[4].'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    }
    usleep(10000);
}
try {
    [$order, $created] = app(OrderService::class)->create($body, $actor);
    echo json_encode(['status' => 200, 'id' => $order->id, 'created' => $created], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
}
