<?php

namespace App\Http\Middleware;

use App\Models\PriceGroup;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolve price group for catalog/user endpoints.
 *
 * Priority:
 *  1) Bearer token (if present + valid) -> user.price_group_id (default 1)
 *  2) (Admin only) override via query ?price_group_id= or header X-Price-Group-Id
 *  3) Default 1
 */
class ResolvePriceGroup
{
    public function handle(Request $request, Closure $next)
    {
        $priceGroupId = PriceGroup::DEFAULT_ID;
        $user = null;

        // 1) If Authorization bearer token موجود: حاول parse بدون فرض auth
        $authHeader = (string) $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '') {
                try {
                    $user = auth('api')->setToken($token)->user();
                    $ug = (int) ($user?->price_group_id ?? PriceGroup::DEFAULT_ID);
                    if ($ug > 0) {
                        $priceGroupId = $ug;
                    }
                } catch (\Throwable $e) {
                    // Ignore invalid/expired tokens for public endpoints
                    $user = null;
                }
            }
        }

        // 2) Admin preview override
        $hint = $request->query('price_group_id') ?? $request->header('X-Price-Group-Id');
        if ($hint !== null && $user && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            $candidate = (int) $hint;
            if ($candidate > 0) {
                $priceGroupId = $candidate;
            }
        }

        // Sanity + existence check (fallback to default)
        if ($priceGroupId <= 0) {
            $priceGroupId = PriceGroup::DEFAULT_ID;
        }

        try {
            $exists = PriceGroup::query()
                ->where('id', $priceGroupId)
                ->where('is_active', true)
                ->exists();
            if (!$exists) {
                $priceGroupId = PriceGroup::DEFAULT_ID;
            }
        } catch (\Throwable $e) {
            $priceGroupId = PriceGroup::DEFAULT_ID;
        }

        app()->instance('price_group_id', $priceGroupId);

        return $next($request);
    }
}
