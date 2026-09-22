<?php

declare(strict_types=1);

/**
 * Autoloading entry point.
 *
 * Composer's autoloader is used whenever dependencies have been installed. The
 * PSR-4 fallback below keeps the application (and `php worker.php`) runnable on
 * a fresh checkout before `composer install`, since the runtime itself has no
 * third-party requirements - only the test suite does.
 */

$composer = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'AccountCheck\\';
    $baseDir = dirname(__DIR__) . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
