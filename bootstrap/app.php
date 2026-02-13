<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php', // ✅ API routes
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // ✅ Global middleware (يشتغل على web + api)
        $middleware->append([
            \App\Http\Middleware\RequestId::class,
        ]);

        // ✅ Alias للـ middleware
        $middleware->alias([
            'user.currency' => \App\Http\Middleware\EnforceUserCurrency::class,
            'admin' => \App\Http\Middleware\RequireAdmin::class,
        ]);

        // (اختياري) إذا بدك يشتغل على كل API حتى بدون auth:
        // $middleware->api(append: [\App\Http\Middleware\EnforceUserCurrency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // اتركها فاضية إذا كنت بتعالج داخل App\Exceptions\Handler
    })
    ->create();

// ✅ اربط Handler (لو عندك app/Exceptions/Handler.php)
$app->singleton(ExceptionHandlerContract::class, \App\Exceptions\Handler::class);

return $app;
