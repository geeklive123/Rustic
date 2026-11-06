<?php

use App\Models\User;
use App\Services\ManualQrPaymentService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
try {
    [$order, $created] = app(ManualQrPaymentService::class)->confirm($data['uuid'], $data, User::findOrFail((int) $sellerId));
    echo json_encode(['status' => 200, 'created' => $created], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
}
