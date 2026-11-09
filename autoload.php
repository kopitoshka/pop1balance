<?php

declare(strict_types=1);

// Uses Composer's autoloader when present (dev: phpunit), otherwise a tiny PSR-4 loader.
// The bot has no third-party runtime dependencies, so production needs no `composer install`.
$composer = __DIR__ . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'P1Bot\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
