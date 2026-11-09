<?php

declare(strict_types=1);

// Run every minute from crontab:
//   * * * * * /usr/bin/php /var/www/pop1bot/bin/cron.php >> /var/www/pop1bot/storage/logs/cron.log 2>&1
// Sends reminders (REMINDER_MINUTES before start), moves registration -> running at start time
// and running -> finished FINISH_AFTER_HOURS later, deletes expired AI dialogs.

use P1Bot\App\CronRunner;
use P1Bot\Bootstrap;
use P1Bot\Support\Logger;

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
$logger = new Logger($config['paths']['logs']);

$lock = fopen($config['paths']['logs'] . '/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still working
}

try {
    $app = Bootstrap::fromConfig($config);
    $app->ensureOwner();
    $result = $app->cron()->run();
    if ($result['reminders'] > 0 || $result['status_changes'] > 0) {
        $logger->info('cron', $result);
    }
} catch (Throwable $e) {
    $logger->exception($e, 'cron');
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
