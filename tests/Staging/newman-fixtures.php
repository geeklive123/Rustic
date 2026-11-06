<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$base = dirname(__DIR__, 2);
if (basename($base) !== '.staging-newman-disposable' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'sqlite'
    || realpath((string) getenv('DB_DATABASE')) !== realpath($base.'/isolated.sqlite')) {
    throw new RuntimeException('Requires the isolated Newman test copy and SQLite database.');
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->environment() !== 'testing' || $app['config']->get('database.default') !== 'sqlite'
    || realpath($app['config']->get('database.connections.sqlite.database')) !== realpath($base.'/isolated.sqlite')) {
    throw new RuntimeException('Unexpected effective test configuration.');
}
$password = bin2hex(random_bytes(20));
$accounts = [];
foreach ([Role::Administrator, Role::Seller, Role::Kitchen] as $role) {
    $email = bin2hex(random_bytes(12)).'@example.invalid';
    $user = User::create(['name' => 'Temporary test', 'email' => $email, 'password' => $password, 'role' => $role, 'is_active' => true]);
    $accounts[$role->value] = ['id' => $user->id, 'email' => $email, 'token' => $user->createToken('isolated-test')->plainTextToken];
}
$target = User::create(['name' => 'Temporary target', 'email' => bin2hex(random_bytes(12)).'@example.invalid', 'password' => $password, 'role' => Role::Seller, 'is_active' => true]);
$category = Category::create(['name' => 'Test category', 'is_active' => true]);
Product::create(['name' => 'Test product', 'category_id' => $category->id, 'price_cents' => 2500, 'is_active' => true]);
echo json_encode(['accounts' => $accounts, 'password' => $password, 'target_id' => $target->id, 'category_id' => $category->id], JSON_THROW_ON_ERROR);
