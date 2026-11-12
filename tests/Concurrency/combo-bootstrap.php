<?php

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Cached configuration is not permitted.');
}
$app->make(Kernel::class)->bootstrap();
$config = $app['config'];
if ($app->environment() !== 'testing' || $config->get('database.default') !== 'mysql' || $config->get('database.connections.mysql.host') !== '127.0.0.1'
    || ! in_array($config->get('database.connections.mysql.database'), ['rustik_pos_combo_test_20261115', 'rustik_pos_release_schema_20261112'], true) || $config->get('database.connections.mysql.url')
    || $app['db']->connection()->selectOne('SELECT DATABASE() AS db')->db !== $config->get('database.connections.mysql.database')) {
    throw new RuntimeException('Requires the dedicated local combo test database.');
}

return $app;
