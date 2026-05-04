<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver'         => 'mysql',
            // Use Railway's injected MySQL variables directly
            'host'           => env('MYSQLHOST',     env('DB_HOST',     '127.0.0.1')),
            'port'           => env('MYSQLPORT',     env('DB_PORT',     '3306')),
            'database'       => env('MYSQLDATABASE', env('DB_DATABASE', 'railway')),
            'username'       => env('MYSQLUSER',     env('DB_USERNAME', 'root')),
            'password'       => env('MYSQLPASSWORD', env('DB_PASSWORD', '')),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => null,
            'options'        => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ],

    'migrations' => [
        'table'  => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client'  => env('REDIS_CLIENT', 'phpredis'),
        'default' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],
];
