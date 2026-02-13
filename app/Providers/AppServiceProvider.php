<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Rate limits
        RateLimiter::for('auth', function (Request $request) {
            return [Limit::perMinute(10)->by('ip:' . $request->ip())];
        });

        RateLimiter::for('orders', function (Request $request) {
            $key = $request->user()?->id ? 'u:' . $request->user()->id : 'ip:' . $request->ip();
            return [Limit::perMinute(30)->by($key)];
        });

        RateLimiter::for('wallet', function (Request $request) {
            $key = $request->user()?->id ? 'wallet:u:' . $request->user()->id : 'wallet:ip:' . $request->ip();
            return [Limit::perMinute(10)->by($key)];
        });

        RateLimiter::for('admin', function (Request $request) {
            $key = $request->user()?->id ? 'admin:u:' . $request->user()->id : 'admin:ip:' . $request->ip();
            return [Limit::perMinute(60)->by($key)];
        });

        // Scramble: Bearer auth in docs
        if (class_exists(Scramble::class)) {
            Scramble::afterOpenApiGenerated(function (OpenApi $openApi, OpenApiContext $context) {
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                        ->as('bearerAuth')
                        ->setDescription('JWT Bearer token. Header: Authorization: Bearer {token}')
                );
            });
        }
    }
}
