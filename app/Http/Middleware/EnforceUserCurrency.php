<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnforceUserCurrency
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        // Admin: لا نقيّد بالعملة ولا نثبت عملة
        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            app()->instance('user_currency', null);
            return $next($request);
        }

        $currency = strtoupper((string) ($user->currency ?? ''));

        if (!in_array($currency, ['TRY', 'SYP'], true)) {
            $rid = $request->attributes->get('request_id') ?? $request->header('X-Request-Id');

            return response()->json([
                'data' => null,
                'meta' => $rid ? ['request_id' => (string) $rid] : (object)[],
                'errors' => [
                    'message' => 'Currency required',
                    'details' => [
                        'currency' => ['Currency required'],
                    ],
                ],
            ], 422);
        }

        app()->instance('user_currency', $currency);
        return $next($request);
    }
}
