<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', implode(',', array_filter([
        env('FRONTEND_URL'),
        env('EXTRA_ORIGINS'),
        'http://localhost:3000',
        'http://localhost:3001',
    ]))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
     * No longer need supports_credentials since we switched from cookies to
     * a token sent as X-Auth-Token header. Tokens work fine cross-origin.
     */
    'supports_credentials' => false,
];
