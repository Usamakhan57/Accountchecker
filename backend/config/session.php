<?php

declare(strict_types=1);

use AccountCheck\Core\Env;

return [
    'cookie_name' => Env::string('SESSION_COOKIE_NAME', 'accountcheck_session'),
    'lifetime_minutes' => Env::int('SESSION_LIFETIME_MINUTES', 720),
    'idle_timeout_minutes' => Env::int('SESSION_IDLE_TIMEOUT_MINUTES', 120),
    'cookie' => [
        // HttpOnly always: the session id is never readable from JavaScript.
        'http_only' => true,
        'secure' => Env::bool('SESSION_COOKIE_SECURE', false),
        'same_site' => Env::string('SESSION_COOKIE_SAMESITE', 'Lax'),
        'domain' => Env::string('SESSION_COOKIE_DOMAIN', ''),
        'path' => '/',
    ],
];
