<?php

declare(strict_types=1);

// Run every minute: php bin/cron.php
// Stub: will send reminders (TournamentService::dueForReminder + markReminded)
// and move tournaments to 'running' at start time.

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

fwrite(STDOUT, "cron: not implemented yet\n");
