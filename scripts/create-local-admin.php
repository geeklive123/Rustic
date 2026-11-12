<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = $app['config'];
$mysql = $config->get('database.connections.mysql');
if (! $app->environment('local') || $config->get('database.default') !== 'mysql'
    || ! in_array($mysql['host'] ?? null, ['127.0.0.1', 'localhost'], true)
    || ($mysql['database'] ?? null) !== 'rustik_pos_dev' || ! empty($mysql['url'])
    || ! empty($mysql['unix_socket'])) {
    fwrite(STDERR, "Se requiere APP_ENV=local y la base local rustik_pos_dev; operación cancelada.\n");
    exit(1);
}
$db = $app['db']->connection();
if ($db->selectOne('SELECT DATABASE() AS db')->db !== 'rustik_pos_dev') {
    fwrite(STDERR, "La base efectiva no coincide; operación cancelada.\n");
    exit(1);
}

echo "Base local verificada: rustik_pos_dev.\n";
exit($app->handleCommand(new ArgvInput(['artisan', 'rustik:create-first-admin'])));
