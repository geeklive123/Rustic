<?php

require __DIR__.'/bootstrap.php';

use App\Enums\Role;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

$tag = (string) Str::uuid();
$seller = User::factory()->create(['role' => Role::Seller, 'is_active' => true]);
$admin = User::factory()->create(['role' => Role::Administrator, 'is_active' => true]);
$category = Category::create(['name' => 'report-'.$tag, 'is_active' => true]);
$product = Product::create(['name' => 'report-'.$tag, 'category_id' => $category->id, 'is_active' => true, 'price_cents' => 2500]);
$body = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'report-test', 'customer_name' => 'Test',
    'payment_method' => 'CASH', 'expected_total_cents' => 2500,
    'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => []]]];
[$order] = app(OrderService::class)->create($body, $seller);
app(OrderService::class)->create($body, $seller);
$date = null;
for ($attempt = 0; $attempt < 100; $attempt++) {
    $day = CarbonImmutable::create(1990, 1, 1, 0, 0, 0, 'America/La_Paz')->addDays(random_int(0, 10000));
    if (! Order::where('created_at', '>=', $day->utc())->where('created_at', '<', $day->addDay()->utc())->exists()) {
        $date = $day;
        break;
    }
}
if (! $date) {
    throw new RuntimeException('No isolated report date available.');
}
Order::whereKey($order->id)->update(['created_at' => $date->utc()]);
$token = $admin->createToken('report-test')->plainTextToken;
$kernel = app(Kernel::class);
foreach (['summary', 'sales', 'products', 'payments'] as $endpoint) {
    $localDate = $date->format('Y-m-d');
    $request = Request::create('/api/v1/reports/'.$endpoint.'?period=custom&start_date='.$localDate.'&end_date='.$localDate.'&per_page=1', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Report '.$endpoint.' failed: '.$response->getContent());
    }
    $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    if ($endpoint === 'summary' && ($data['data']['order_count'] !== 1 || $data['data']['cash_cents'] !== 2500)) {
        throw new RuntimeException('MariaDB summary incorrect.');
    }
    if ($endpoint === 'sales' && $data['meta']['total'] !== 1) {
        throw new RuntimeException('MariaDB sales incorrect.');
    }
    if ($endpoint === 'products' && ($data['meta']['total'] !== 1 || $data['data'][0]['quantity'] !== 1)) {
        throw new RuntimeException('MariaDB products incorrect.');
    }
    if ($endpoint === 'payments' && ($data['meta']['total'] !== 1 || $data['data'][0]['amount_cents'] !== 2500)) {
        throw new RuntimeException('MariaDB payments incorrect.');
    }
}
echo "MariaDB report routes passed, including grouped pagination and replay.\n";
