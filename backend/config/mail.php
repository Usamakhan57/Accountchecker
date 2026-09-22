<?php

declare(strict_types=1);

use AccountCheck\Core\Env;

return [
    'driver' => Env::string('MAIL_DRIVER', 'log'),
    'host' => Env::string('MAIL_HOST', ''),
    'port' => Env::int('MAIL_PORT', 587),
    'username' => Env::string('MAIL_USERNAME', ''),
    'password' => Env::string('MAIL_PASSWORD', ''),
    'encryption' => Env::string('MAIL_ENCRYPTION', 'tls'),
    'from' => [
        'address' => Env::string('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
        'name' => Env::string('MAIL_FROM_NAME', 'AccountCheck'),
    ],
];
