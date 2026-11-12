<?php

require __DIR__.'/combo-bootstrap.php';

use App\Http\Controllers\Admin\ComboCatalogController;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$input = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
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
    if ($input['type'] === 'sale') {
        [$order, $created] = app(OrderService::class)->create($input['body'], $actor);
        $result = ['status' => 200, 'id' => $order->id, 'created' => $created];
    } else {
        $request = Request::create('/admin/test', 'POST', $input['body']);
        $request->setUserResolver(fn () => $actor);
        app(ComboCatalogController::class)->update($request, Product::findOrFail($input['product']), $input['component']);
        $result = ['status' => 200];
    }
    echo json_encode($result);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
