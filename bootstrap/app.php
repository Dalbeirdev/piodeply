<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(append: \App\Http\Middleware\SecurityHeaders::class);
        $middleware->api(append: \App\Http\Middleware\SecurityHeaders::class);

        // Capture ?ref= affiliate links across the site.
        $middleware->web(append: \App\Http\Middleware\CaptureReferral::class);

        // Stripe posts here from outside the session; the request is
        // HMAC-verified in the controller instead of by CSRF token.
        $middleware->validateCsrfTokens(except: ['billing/webhook', 'stripe/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
