<?php

use App\Http\Middleware\BanCheck;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\SessionAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:       __DIR__.'/../routes/web.php',
        api:       __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        health:    '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'ban.check'    => BanCheck::class,
            'admin.auth'   => AdminAuth::class,
            'session.auth' => SessionAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
        });

        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error'   => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        });

        $exceptions->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Resource not found'], 404);
        });
    })->create();
