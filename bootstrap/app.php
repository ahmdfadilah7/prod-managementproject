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
        if (filled(env('TRUST_PROXIES'))) {
            $at = env('TRUST_PROXIES') === '*' ? '*' : explode(',', (string) env('TRUST_PROXIES'));
            $middleware->trustProxies(at: $at);
        }

        $middleware->statefulApi();
        $middleware->api(append: [
            \App\Http\Middleware\SetUserDisplayTimezone::class,
        ]);
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
