<?php

$app = require __DIR__.'/bootstrap.php';
$db = $app['db']->connection();
$applied = $db->table('migrations')->pluck('migration')->all();
$files = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
$missing = [];
foreach ($files as $file) {
    if (! in_array(basename($file, '.php'), $applied, true)) {
        $missing[] = basename($file);
    }
}
if ($missing !== []) {
    throw new RuntimeException('Pending migrations: '.implode(', ', $missing));
}
echo count($files)." migration files recorded; no pending migrations in isolated database.\n";
