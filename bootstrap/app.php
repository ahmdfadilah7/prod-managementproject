<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return null;
            }

            $errorId = (string) Str::uuid();

            Log::error('ManagementPro API error', [
                'error_id' => $errorId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_id' => $request->user()?->id,
                'input' => $request->except(['password', 'password_confirmation', 'token']),
            ]);

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            if ($status < 400 || $status >= 600) {
                $status = 500;
            }

            $expose = (bool) config('managementpro.api_expose_exception_message', false);

            $message = $expose
                ? $e->getMessage()
                : 'Terjadi kesalahan server. Beri ID ini ke admin: '.$errorId;

            return response()->json([
                'message' => $message,
                'error_id' => $errorId,
            ], $status);
        });
    })->create();
