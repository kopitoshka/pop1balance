<?php

declare(strict_types=1);

// Applies migrations/*.sql in order to the DB from .env. Prototype: no bookkeeping table,
// run once on an empty database.

use P1Bot\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$db = Database::fromConfig($config['db']);
foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
    fwrite(STDOUT, 'Applying ' . basename($file) . "\n");
    $db->pdo()->exec((string) file_get_contents($file));
}
