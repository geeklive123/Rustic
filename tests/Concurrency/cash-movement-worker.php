<?php

require __DIR__.'/bootstrap.php';

use App\Models\User;
use App\Services\CashMovementCompensation;
use App\Services\CashMovementService;
use App\Services\CashShiftService;
use App\Services\SimulatedPaymentCorrection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$job = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
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
    if ($job['type'] === 'close') {
        app(CashShiftService::class)->close($actor, $job['shift'], $job['data']);
        echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
    } elseif ($job['type'] === 'compensation') {
        [, $replayed] = app(CashMovementCompensation::class)->create($actor, $job['shift'], $job['data']);
        echo json_encode(['status' => 200, 'replayed' => $replayed], JSON_THROW_ON_ERROR);
    } elseif ($job['type'] === 'payment_correction') {
        app(SimulatedPaymentCorrection::class)->correct($job['order'], $actor, $job['data']);
        echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
    } else {
        [, $replayed] = app(CashMovementService::class)->create($actor, $job['shift'], $job['data']);
        echo json_encode(['status' => 200, 'replayed' => $replayed], JSON_THROW_ON_ERROR);
    }
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
}
