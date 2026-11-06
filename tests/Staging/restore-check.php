<?php

// Local-only restore rehearsal. The source may contain confidential data: never print it.
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = $app['config'];
if ($app->configurationIsCached() || $app->environment() !== 'local' || $config->get('database.default') !== 'mysql'
    || $config->get('database.connections.mysql.host') !== '127.0.0.1'
    || $config->get('database.connections.mysql.database') !== 'rustik_pos_dev'
    || $config->get('database.connections.mysql.url')) {
    throw new RuntimeException('Restore rehearsal requires uncached local rustik_pos_dev on 127.0.0.1.');
}
$file = realpath(dirname(__DIR__, 2).'/storage/app/private/backups/rustik_pos_dev_before_cash_20261106_053843.sql');
if (! $file || ! is_readable($file)) {
    throw new RuntimeException('Expected private backup is unavailable.');
}
$sql = file_get_contents($file);
if (preg_match('/^\s*(?:USE\s|(?:CREATE|DROP|ALTER)\s+(?:DATABASE|SCHEMA)\b|SOURCE\s|\\\\[.!])|`[^`]+`\s*\.\s*`[^`]+`|\b(?:INTO\s+(?:OUTFILE|DUMPFILE)|LOAD\s+DATA|GRANT|REVOKE)\b/im', $sql)) {
    throw new RuntimeException('Backup contains unsupported or cross-database statements.');
}
$pdo = $app['db']->connection()->getPdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'rustik_pos_dev') {
    throw new RuntimeException('Unexpected source connection.');
}
$name = 'rustik_pos_restore_'.bin2hex(random_bytes(6));
$q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$q->execute([$name]);
if ($q->fetchColumn() != 0) {
    throw new RuntimeException('Disposable database already exists.');
}
$created = false;
try {
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $pdo->exec("USE `$name`");
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
        throw new RuntimeException('Unexpected restore target.');
    }
    $pdo->exec($sql);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
        throw new RuntimeException('Restore target changed unexpectedly.');
    }
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $migrations = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    $foreign = $pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?');
    $foreign->execute([$name]);
    $constraints = (int) $foreign->fetchColumn();
    $counts = [];
    foreach (['users', 'personal_access_tokens', 'orders', 'payments', 'products'] as $table) {
        if (in_array($table, $tables, true)) {
            $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        }
    }
    if (count($tables) < 10 || $migrations < 7 || $constraints < 1) {
        throw new RuntimeException('Restored schema is incomplete.');
    }
    // CHECK TABLE verifies physical integrity; foreign-key orphan checks follow below.
    foreach ($tables as $table) {
        $check = $pdo->query('CHECK TABLE `'.str_replace('`', '``', $table).'`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($check as $row) {
            if ($row['Msg_type'] === 'error') {
                throw new RuntimeException('Table integrity check failed.');
            }
        }
    }
    $fk = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $fk->execute([$name]);
    $checked = 0;
    foreach ($fk->fetchAll(PDO::FETCH_ASSOC) as $key) {
        $id = static fn ($value) => '`'.str_replace('`', '``', $value).'`';
        $child = $id($key['TABLE_NAME']);
        $column = $id($key['COLUMN_NAME']);
        $parent = $id($key['REFERENCED_TABLE_NAME']);
        $reference = $id($key['REFERENCED_COLUMN_NAME']);
        $orphans = $pdo->query("SELECT COUNT(*) FROM $child c LEFT JOIN $parent p ON c.$column = p.$reference WHERE c.$column IS NOT NULL AND p.$reference IS NULL")->fetchColumn();
        if ((int) $orphans !== 0) {
            throw new RuntimeException('Restored foreign key has orphan rows.');
        }
        $checked++;
    }
    echo json_encode(['backup_sha256' => hash_file('sha256', $file), 'table_count' => count($tables), 'migrations' => $migrations, 'foreign_keys' => $constraints, 'foreign_key_columns_checked' => $checked, 'row_counts' => $counts, 'orphan_rows' => 0], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if ($created) {
        if (! preg_match('/^rustik_pos_restore_[a-f0-9]{12}$/', $name)) {
            throw new RuntimeException('Refusing cleanup of unexpected database.');
        }
        $pdo->exec("DROP DATABASE `$name`");
        echo "Disposable restore database removed.\n";
    }
}
