<?php

require __DIR__.'/bootstrap.php';

use App\Models\User;
use App\Services\KitchenService;
use App\Services\OrderNoteCorrection;
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
    if ($body['type'] === 'note') {
        app(OrderNoteCorrection::class)->update($body['id'], $actor, $body['data']);
    } else {
        app(KitchenService::class)->transition($body['uuid'], 'NEW', 'PREPARING', $body['operation'], $actor);
    }
    echo json_encode(['status' => 200]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
