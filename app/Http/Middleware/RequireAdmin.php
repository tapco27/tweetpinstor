<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        $isAdmin = $user && (
            (method_exists($user, 'hasRole') && $user->hasRole('admin'))
            || (($user->role ?? null) === 'admin')
        );

        if (!$isAdmin) {
            $rid = $request->attributes->get('request_id') ?? $request->header('X-Request-Id');

            return response()->json([
                'data' => null,
                'meta' => $rid ? ['request_id' => (string) $rid] : (object)[],
                'errors' => [
                    'message' => 'Forbidden',
                    'details' => (object)[],
                ],
            ], 403);
        }

        return $next($request);
    }
}
