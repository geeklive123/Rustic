<?php

$app = require __DIR__.'/bootstrap.php';
$db = $app['db']->connection();
if ($db->selectOne('SELECT DATABASE() AS db')->db !== 'rustik_pos_release_schema_20261112') {
    throw new RuntimeException('Unexpected database.');
}
if ((int) $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
    throw new RuntimeException('The test database must be empty.');
}
$data = json_decode(file_get_contents(dirname(__DIR__, 2).'/storage/app/release-schema-only.json'), true, 512, JSON_THROW_ON_ERROR);
if (count($data['creates']) !== 22 || count($data['migrations']) !== 10) {
    throw new RuntimeException('Unexpected schema extract.');
}
$db->statement('SET FOREIGN_KEY_CHECKS=0');
try {
    foreach ($data['creates'] as $create) {
        if (! preg_match('/\ACREATE TABLE `[a-z_]+`\s*\(/', $create) || substr_count($create, ';') !== 1) {
            throw new RuntimeException('Unexpected schema statement.');
        }
        $db->unprepared($create);
    }
    foreach ($data['migrations'] as [$migration, $batch]) {
        $db->table('migrations')->insert(['migration' => $migration, 'batch' => (int) $batch]);
    }
} finally {
    $db->statement('SET FOREIGN_KEY_CHECKS=1');
}
echo "Imported production backup schema and migration history only; no business rows.\n";
