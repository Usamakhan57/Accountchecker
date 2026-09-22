<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Tests run against the mock checker engine and never touch an external API.
 * Database-backed tests skip themselves when no test database is configured,
 * so `composer test` stays green on a fresh checkout.
 */

require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use AccountCheck\Core\Env;

Env::load(dirname(__DIR__) . '/.env.testing');

// Deterministic defaults for anything the test env file did not set.
foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_TIMEZONE' => 'UTC',
    'CHECKER_MODE' => 'mock',
    'MAIL_DRIVER' => 'log',
    'LOG_LEVEL' => 'error',
] as $key => $value) {
    if (getenv($key) === false && Env::get($key) === null) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

date_default_timezone_set('UTC');
