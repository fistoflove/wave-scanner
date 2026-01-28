<?php

return [
    // Core
    'APP_RUNTIME' => 'swoole',
    'APP_HOST' => '0.0.0.0',
    'APP_PORT' => 9504,
    'APP_DEBUG' => false,
    'APP_MAX_BODY' => 1024 * 1024 * 5,

    // Auth (optional overrides)
    'APP_USER' => 'admin',
    'APP_PASS' => 'amada',

    // MySQL (required)
    'MYSQL_HOST' => '138.197.47.3',
    'MYSQL_PORT' => 3306,
    'MYSQL_USER' => 'yvcjucnpvp',
    'MYSQL_PASSWORD' => '36FD2fj66q',
    'MYSQL_DATABASE' => 'yvcjucnpvp',
    'MYSQL_CHARSET' => 'utf8mb4',
    'MYSQL_TIMEOUT' => 1.0,

    // Redis (optional)
    'REDIS_HOST' => '',
    'REDIS_PORT' => 6379,
    'REDIS_AUTH' => null,
    'REDIS_DB' => null,
    'REDIS_TIMEOUT' => 1.0,

    // Admin reset endpoint (dangerous)
    'APP_ALLOW_RESET' => 1,
];
