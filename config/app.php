<?php

return [
    'name'     => env('APP_NAME', 'FidgeBackend'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale'   => 'en',
    'fallback_locale' => 'en',
    'faker_locale'    => 'en_US',
    'key'      => env('APP_KEY'),
    'cipher'   => 'AES-256-CBC',
    'maintenance' => ['driver' => 'file'],
    'providers' => \Illuminate\Support\ServiceProvider::defaultProviders()->merge([
        App\Providers\AppServiceProvider::class,
    ])->toArray(),
    'aliases' => \Illuminate\Support\Facades\Facade::defaultAliases()->merge([
    ])->toArray(),
];
