<?php

declare(strict_types=1);

use AccountCheck\Core\Env;

return [
    'host' => Env::string('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'database' => Env::string('DB_NAME', 'accountcheck'),
    'username' => Env::string('DB_USER', ''),
    'password' => Env::string('DB_PASSWORD', ''),
    'charset' => Env::string('DB_CHARSET', 'utf8mb4'),
];
