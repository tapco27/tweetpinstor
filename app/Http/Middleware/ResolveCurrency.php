<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Resolve currency for catalog/public endpoints.
 *
 * Priority:
 *  1) Auth token (if present + valid) -> user.currency
 *  2) Query param ?currency=TRY|SYP or header X-Currency
 *  3) Default TRY
 *
 * This middleware does NOT enforce authentication.
 */
class ResolveCurrency
{
    public function handle(Request $request, Closure $next)
    {
        $currency = null;

        // 1) If Authorization bearer token موجود: حاول parse بدون فرض auth
        $authHeader = (string) $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '') {
                try {
                    $user = auth('api')->setToken($token)->user();
                    $uc = strtoupper((string) ($user?->currency ?? ''));
                    if (in_array($uc, ['TRY', 'SYP'], true)) {
                        $currency = $uc;
                    }
                } catch (\Throwable $e) {
                    // Ignore invalid/expired tokens for public endpoints
                    $currency = $currency; // no-op
                }
            }
        }

        // 2) Explicit currency (query/header)
        if (!$currency) {
            $hint = strtoupper(trim((string) ($request->query('currency') ?? $request->header('X-Currency', ''))));
            if (in_array($hint, ['TRY', 'SYP'], true)) {
                $currency = $hint;
            }
        }

        // 3) Default
        if (!$currency) {
            $currency = 'TRY';
        }

        app()->instance('user_currency', $currency);
        return $next($request);
    }
}
