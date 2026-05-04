<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
        {
            // Check helper OR raw env variable OR the APP_ENV config directly
            if (app()->environment('production') || env('APP_ENV') === 'production' || config('app.env') === 'production') {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }
        }
}
