<?php

// Run only from the explicitly named, disposable staging copy and SQLite file.
use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

$base = dirname(__DIR__, 2);
if (basename($base) !== '.staging-2.0f-disposable' || getenv('APP_ENV') !== 'staging'
    || getenv('APP_DEBUG') !== 'false' || getenv('DB_CONNECTION') !== 'sqlite'
    || realpath((string) getenv('DB_DATABASE')) !== realpath($base.'/isolated.sqlite')) {
    throw new RuntimeException('Requires the disposable staging copy and its isolated SQLite database.');
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->environment() !== 'staging' || $app['config']->get('app.debug')
    || $app['config']->get('database.default') !== 'sqlite'
    || realpath($app['config']->get('database.connections.sqlite.database')) !== realpath($base.'/isolated.sqlite')) {
    throw new RuntimeException('Unexpected effective staging configuration.');
}
$password = bin2hex(random_bytes(20));
$users = [];
foreach ([Role::Administrator, Role::Seller, Role::Kitchen] as $role) {
    $email = bin2hex(random_bytes(12)).'@example.invalid';
    User::create(['name' => 'Temporary test', 'email' => $email, 'password' => $password, 'role' => $role, 'is_active' => true]);
    $users[$role->value] = $email;
}
$category = Category::create(['name' => 'Test category', 'is_active' => true]);
$product = Product::create(['name' => 'Test product', 'category_id' => $category->id, 'price_cents' => 2500, 'is_active' => true]);
$occupied = @fsockopen('127.0.0.1', 18763, $errno, $error, 0.1);
if ($occupied) {
    fclose($occupied);
    throw new RuntimeException('Loopback smoke port is already in use.');
}
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$pipes = [];
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18763', '-t', 'public', 'public/index.php'], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']], $pipes, $base);
if (! is_resource($server)) {
    throw new RuntimeException('Could not start loopback server.');
}
try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', 18763, $errno, $error, 0.1);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (! $ready) {
        throw new RuntimeException('Loopback server did not start.');
    }
    $checks = 0;
    $request = function (string $method, string $path, ?array $body = null, ?string $token = null) use (&$checks): array {
        $curl = curl_init('http://127.0.0.1:18763/api/v1'.$path);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer '.$token;
        }
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($curl);
        if ($raw === false) {
            throw new RuntimeException('HTTP request failed.');
        }
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $checks++;

        return [$code, json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
    };
    $expect = static function (bool $condition, string $label): void {
        if (! $condition) {
            throw new RuntimeException('HTTP smoke failed: '.$label);
        }
    };
    [$code, $json] = $request('GET', '/health');
    $expect($code === 200 && ($json['data']['status'] ?? null) === 'ok', 'health');
    [$code] = $request('GET', '/me');
    $expect($code === 401, 'anonymous');
    [$code] = $request('POST', '/login', ['email' => $users[Role::Seller->value], 'password' => bin2hex(random_bytes(20)), 'device_name' => 'test']);
    $expect(in_array($code, [401, 422], true), 'invalid login');
    $tokens = [];
    foreach ($users as $role => $email) {
        [$code, $json] = $request('POST', '/login', ['email' => $email, 'password' => $password, 'device_name' => 'test']);
        $expect($code === 200 && ! empty($json['data']['access_token']), 'login '.$role);
        $tokens[$role] = $json['data']['access_token'];
    }
    $seller = $tokens[Role::Seller->value];
    $kitchen = $tokens[Role::Kitchen->value];
    $admin = $tokens[Role::Administrator->value];
    [$code] = $request('GET', '/me', null, $seller);
    $expect($code === 200, 'me');
    [$code] = $request('GET', '/reports/summary', null, $kitchen);
    $expect($code === 403, 'kitchen denied financial reports');
    [$code, $json] = $request('GET', '/catalog', null, $seller);
    $expect($code === 200 && count($json['data']['products'] ?? []) === 1, 'catalog');
    $open = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'opening_cents' => 500];
    [$code, $json] = $request('POST', '/cash-shifts/open', $open, $seller);
    $expect($code === 201 && isset($json['data']['id']), 'cash open');
    $shift = $json['data']['id'];
    $order = ['uuid' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(), 'device_id' => 'test-device', 'customer_name' => 'Test', 'payment_method' => 'CASH', 'expected_total_cents' => 2500, 'cash_shift_id' => $shift, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_unit_price_cents' => 2500, 'extras' => [], 'option_ids' => []]]];
    [$code] = $request('POST', '/orders', $order, $seller);
    $expect($code === 201, 'order created');
    [$code, $json] = $request('POST', '/orders', $order, $seller);
    $expect($code === 200 && ($json['data']['uuid'] ?? null) === $order['uuid'], 'order replay');
    [$code, $json] = $request('GET', '/kitchen/orders?status=NEW', null, $kitchen);
    $expect($code === 200 && count($json['data'] ?? []) === 1 && ! preg_match('/price|total_cents|payment/i', json_encode($json)), 'kitchen queue');
    [$code] = $request('PATCH', '/kitchen/orders/'.$order['uuid'].'/status', ['expected_status' => 'NEW', 'status' => 'PREPARING', 'operation_id' => (string) Str::uuid()], $kitchen);
    $expect($code === 200, 'kitchen transition');
    [$code, $json] = $request('GET', '/reports/summary?period=today', null, $admin);
    $expect($code === 200 && ($json['data']['order_count'] ?? null) === 1, 'report');
    [$code, $json] = $request('POST', '/cash-shifts/'.$shift.'/close', ['operation_id' => (string) Str::uuid(), 'counted_cents' => 3000], $seller);
    $expect($code === 200 && ($json['data']['difference_cents'] ?? null) === 0, 'cash close');
    [$code] = $request('POST', '/logout', null, $seller);
    $expect($code === 200, 'logout');
    [$code] = $request('GET', '/me', null, $seller);
    $expect($code === 401, 'revoked token');
    echo "HTTP smoke passed: $checks requests, isolated SQLite, loopback only.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
}
