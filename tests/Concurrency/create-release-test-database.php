<?php

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Cached configuration is not permitted.');
}
$app->make(Kernel::class)->bootstrap();
$config = $app['config'];
$name = 'rustik_pos_release_schema_20261112';
if ($app->environment() !== 'testing' || $config->get('database.default') !== 'mysql'
    || $config->get('database.connections.mysql.database') !== $name
    || $config->get('database.connections.mysql.host') !== '127.0.0.1'
    || $config->get('database.connections.mysql.url') || $config->get('database.connections.mysql.unix_socket')) {
    throw new RuntimeException('Unexpected database configuration.');
}
$config->set('database.connections.mysql.database', null);
$app['db']->connection('mysql')->statement('CREATE DATABASE `rustik_pos_release_schema_20261112` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "Created isolated release test database.\n";
