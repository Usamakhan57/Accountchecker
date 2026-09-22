<?php

declare(strict_types=1);

use AccountCheck\Core\Env;

return [
    'name' => Env::string('APP_NAME', 'AccountCheck'),
    'env' => Env::string('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => rtrim(Env::string('APP_URL', 'http://localhost:8080'), '/'),
    'timezone' => Env::string('APP_TIMEZONE', 'UTC'),
    'key' => Env::string('APP_KEY', ''),

    // CORS: an explicit origin allow-list. "*" is deliberately not supported,
    // because the API answers with credentialed cookies.
    'cors' => [
        'allowed_origins' => Env::list('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-Token', 'X-Requested-With'],
        'exposed_headers' => ['X-RateLimit-Remaining', 'X-RateLimit-Reset'],
        'max_age' => 600,
        'supports_credentials' => true,
    ],

    // Runs before routing, so unknown paths and preflight get them too.
    'global_middleware' => [
        AccountCheck\Middleware\CorsMiddleware::class,
        // Before routing, so an unsafe request to a path that does not exist is
        // refused for want of a token rather than probing the route table.
        AccountCheck\Middleware\CsrfMiddleware::class,
    ],

    // Applied to every response on the way out.
    'response_decorators' => [
        AccountCheck\Middleware\CorsMiddleware::class,
        AccountCheck\Middleware\SecurityHeadersMiddleware::class,
        // Issues the token cookie on the way out, so a client's first read
        // leaves it able to make a write.
        AccountCheck\Middleware\CsrfMiddleware::class,
    ],

    'rate_limits' => [
        'login' => [
            'attempts' => Env::int('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
            'window_minutes' => Env::int('RATE_LIMIT_LOGIN_WINDOW_MINUTES', 15),
        ],
        'api' => [
            'attempts' => Env::int('RATE_LIMIT_API_REQUESTS', 300),
            'window_minutes' => Env::int('RATE_LIMIT_API_WINDOW_MINUTES', 1),
        ],
        'register' => ['attempts' => 5, 'window_minutes' => 60],
        'password_reset' => ['attempts' => 5, 'window_minutes' => 60],
        'checker_start' => ['attempts' => 30, 'window_minutes' => 10],
        'support' => ['attempts' => 20, 'window_minutes' => 60],
        // The free tools cost no credits, so a limit is the only thing
        // standing between one account and everyone else's CPU.
        'tools' => ['attempts' => 60, 'window_minutes' => 1],
    ],

    'storage' => [
        'root' => dirname(__DIR__) . '/storage',
        'logs' => dirname(__DIR__) . '/storage/logs',
        'uploads' => dirname(__DIR__) . '/storage/uploads',
        'exports' => dirname(__DIR__) . '/storage/exports',
    ],

    'log_level' => Env::string('LOG_LEVEL', 'info'),
];
