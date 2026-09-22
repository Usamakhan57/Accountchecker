<?php

declare(strict_types=1);

use AccountCheck\Checkers\HttpClient;
use AccountCheck\Core\Application;
use AccountCheck\Core\Config;
use AccountCheck\Core\Container;
use AccountCheck\Core\Database;
use AccountCheck\Core\Env;
use AccountCheck\Core\Router;
use AccountCheck\Support\Logger;

/**
 * Builds the service container and returns it.
 *
 * Shared by the HTTP front controller, the CLI worker and the test bootstrap so
 * all three see exactly the same wiring.
 */
require_once __DIR__ . '/autoload.php';

$basePath = dirname(__DIR__);

Env::load($basePath . '/.env');

$config = new Config($basePath . '/config');

date_default_timezone_set($config->string('app.timezone', 'UTC'));

// Errors are logged, never rendered. display_errors stays off in production so
// no path, query or stack frame can reach a client.
$isProduction = $config->string('app.env') === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$container = new Container();

$container->instance(Config::class, $config);

$container->singleton(Logger::class, static fn (Container $c): Logger => new Logger(
    $config->string('app.storage.logs', $basePath . '/storage/logs'),
    $config->string('app.log_level', 'info'),
    'app',
));

$container->singleton(Database::class, static fn (): Database => new Database(
    $config->array('database'),
));

// The checker HTTP client needs its timeouts and retry policy from config, so
// it is bound explicitly rather than autowired.
$container->singleton(HttpClient::class, static fn (Container $c): HttpClient => new HttpClient(
    $c->get(Logger::class),
    $config->int('checkers.http.timeout', 15),
    $config->int('checkers.http.max_retries', 3),
    $config->int('checkers.http.retry_base_delay_ms', 500),
    $config->string('checkers.http.user_agent', 'AccountCheck/1.0'),
));

$container->singleton(Router::class, static function () use ($basePath): Router {
    $router = new Router();
    (require $basePath . '/routes/api.php')($router);

    return $router;
});

$container->singleton(Application::class, static fn (Container $c): Application => new Application(
    $c,
    $c->get(Router::class),
    $c->get(Config::class),
    $c->get(Logger::class),
));

return $container;
