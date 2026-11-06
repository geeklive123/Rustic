<?php

use Illuminate\Contracts\Console\Kernel;

// This harness never uses PHPUnit's SQLite configuration. It refuses any other database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Clear cached configuration before the concurrency test.');
}
$app->make(Kernel::class)->bootstrap();
$config = $app['config'];
if ($app->environment() !== 'testing' || $config->get('database.default') !== 'mysql'
    || $config->get('database.connections.mysql.database') !== 'rustik_pos_concurrency_test'
    || $config->get('database.connections.mysql.host') !== '127.0.0.1'
    || $config->get('database.connections.mysql.url')) {
    throw new RuntimeException('Requires explicit local rustik_pos_concurrency_test MySQL database and APP_ENV=testing.');
}
$db = $app['db']->connection();
if ($db->selectOne('SELECT DATABASE() AS db')->db !== 'rustik_pos_concurrency_test') {
    throw new RuntimeException('Connected to an unexpected database.');
}

return $app;
