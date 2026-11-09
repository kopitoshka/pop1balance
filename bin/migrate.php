<?php

declare(strict_types=1);

// Applies new migrations/*.sql in order: php bin/migrate.php
// Applied files are recorded in schema_migrations. If the table does not exist yet but the
// schema does (001 was imported by hand), 001_init.sql is marked as applied automatically.

use P1Bot\Support\Database;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$db = Database::fromConfig($config['db']);
$pdo = $db->pdo();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('schema_migrations', $tables, true)) {
    $pdo->exec('CREATE TABLE schema_migrations (
        name VARCHAR(191) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (in_array('players', $tables, true)) {
        $pdo->exec("INSERT INTO schema_migrations (name) VALUES ('001_init.sql')");
        fwrite(STDOUT, "Existing schema found: 001_init.sql marked as applied\n");
    }
}

$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);
$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    fwrite(STDOUT, "Applying $name\n");
    $pdo->exec((string) file_get_contents($file));
    $db->run('INSERT INTO schema_migrations (name) VALUES (:n)', ['n' => $name]);
    $count++;
}
fwrite(STDOUT, $count === 0 ? "Nothing to apply, schema is up to date\n" : "Done: $count migration(s)\n");
