<?php

namespace App\Services\Providers;

use App\Models\ProviderIntegration;
use Illuminate\Support\Facades\Http;

/**
 * Tweet-Pin API Client
 *
 * Base URL: https://api.tweet-pin.com/
 * Auth Header: api-token: YOUR_API_TOKEN
 */
final class TweetPinApiClient
{
    /**
     * GET /client/api/profile
     */
    public function profile(ProviderIntegration $integration): array
    {
        return $this->get($integration, '/client/api/profile', [], false);
    }

    /**
     * GET /client/api/products
     * Optional:
     * - products_id: id1,id2,id3
     * - base=1 minimal response
     *
     * @param array<int,int|string>|null $productIds
     */
    public function products(ProviderIntegration $integration, ?array $productIds = null, bool $base = false): array
    {
        $q = [];

        if (is_array($productIds) && count($productIds) > 0) {
            $ids = [];
            foreach ($productIds as $id) {
                if (is_numeric($id)) {
                    $ids[] = (string) ((int) $id);
                } elseif (is_string($id) && trim($id) !== '') {
                    $ids[] = trim($id);
                }
            }
            if (count($ids) > 0) {
                $q['products_id'] = implode(',', $ids);
            }
        }

        if ($base) {
            $q['base'] = 1;
        }

        return $this->get($integration, '/client/api/products', $q, false);
    }

    /**
     * GET /client/api/content/{parentId}
     */
    public function content(ProviderIntegration $integration, int $parentId = 0): array
    {
        $parentId = max(0, (int) $parentId);
        return $this->get($integration, '/client/api/content/' . $parentId, [], false);
    }

    /**
     * GET /client/api/newOrder/{productId}/params
     *
     * Important: order_uuid is required for idempotency.
     *
     * @param array<string,mixed> $params
     */
    public function newOrder(
        ProviderIntegration $integration,
        int $productId,
        int $qty,
        array $params,
        string $orderUuid
    ): array {
        $productId = (int) $productId;
        $qty = max(1, (int) $qty);
        $orderUuid = trim((string) $orderUuid);

        $q = [
            'qty' => $qty,
            'order_uuid' => $orderUuid,
        ];

        // Merge arbitrary params (playerId/uid/email/walletId etc.)
        foreach ($params as $k => $v) {
            if (!is_string($k) || trim($k) === '') {
                continue;
            }

            if (!(is_scalar($v) || $v === null)) {
                continue;
            }

            $q[trim($k)] = $v;
        }

        return $this->get($integration, "/client/api/newOrder/{$productId}/params", $q, true);
    }

    /**
     * GET /client/api/check
     *
     * Docs require orders param as string: [ID_1,ID_2]
     *
     * @param array<int,string> $orders
     */
    public function check(ProviderIntegration $integration, array $orders, bool $uuid = false): array
    {
        $clean = [];
        foreach ($orders as $o) {
            $o = trim((string) $o);
            if ($o !== '') {
                // keep raw IDs (ID_xxx or UUID)
                $clean[] = $o;
            }
        }

        $q = [
            'orders' => '[' . implode(',', $clean) . ']',
        ];

        if ($uuid) {
            $q['uuid'] = 1;
        }

        return $this->get($integration, '/client/api/check', $q, true);
    }

    /**
     * @param array<string,mixed> $query
     */
    private function get(ProviderIntegration $integration, string $path, array $query, bool $expectStatusOk): array
    {
        $baseUrl = $this->resolveBaseUrl($integration);
        $token = $this->resolveToken($integration);

        if ($token === '') {
            return [
                'ok' => false,
                'http_status' => null,
                'json' => null,
                'raw' => null,
                'error' => 'Missing Tweet-Pin api_token in ProviderIntegration.credentials',
                'error_code' => 120,
            ];
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        $timeout = (int) (config('services.tweet_pin.timeout') ?? 20);
        $timeout = $timeout > 0 ? $timeout : 20;

        $retries = (int) (config('services.tweet_pin.retries') ?? 1);
        $retries = $retries >= 0 ? $retries : 0;

        try {
            $resp = Http::timeout($timeout)
                ->retry($retries, 200)
                ->withHeaders([
                    'api-token' => $token,
                ])
                ->acceptJson()
                ->get($url, $query);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'http_status' => null,
                'json' => null,
                'raw' => null,
                'error' => $e->getMessage(),
                'error_code' => null,
            ];
        }

        $json = $resp->json();
        $raw = $resp->body();

        $ok = $resp->successful();
        $errorCode = $this->extractErrorCode($json);

        // Some endpoints return status=OK in JSON.
        if ($ok && $expectStatusOk && is_array($json)) {
            $rootStatus = $json['status'] ?? null;
            if (is_string($rootStatus) && strtoupper(trim($rootStatus)) !== 'OK') {
                $ok = false;
            }
        }

        $error = null;
        if (!$ok) {
            $error = $this->extractErrorMessage($json);
            if (!$error) {
                $error = $resp->reason() ?: 'Tweet-Pin request failed';
            }
        }

        return [
            'ok' => (bool) $ok,
            'http_status' => (int) $resp->status(),
            'json' => $json,
            'raw' => $raw,
            'error' => $error,
            'error_code' => $errorCode,
        ];
    }

    private function resolveBaseUrl(ProviderIntegration $integration): string
    {
        $creds = $integration->credentials;

        $base = null;
        if (is_array($creds)) {
            $base = $creds['base_url'] ?? null;
        }

        $base = is_string($base) ? trim($base) : '';

        if ($base === '') {
            $base = (string) (config('services.tweet_pin.base_url') ?? 'https://api.tweet-pin.com');
        }

        // Some people store base url without scheme.
        if (!str_starts_with($base, 'http://') && !str_starts_with($base, 'https://')) {
            $base = 'https://' . ltrim($base, '/');
        }

        return $base;
    }

    private function resolveToken(ProviderIntegration $integration): string
    {
        $creds = $integration->credentials;

        $token = null;
        if (is_array($creds)) {
            $token = $creds['api_token'] ?? $creds['api-token'] ?? $creds['token'] ?? null;
        }

        $token = is_string($token) ? trim($token) : '';

        // Optional fallback (legacy): services.fulfillment.token
        if ($token === '') {
            $legacy = (string) (config('services.fulfillment.token') ?? '');
            $token = trim($legacy);
        }

        return $token;
    }

    private function extractErrorCode($json): ?int
    {
        if (!is_array($json)) return null;

        $candidates = [
            'code',
            'error_code',
            'errorCode',
        ];

        foreach ($candidates as $k) {
            if (array_key_exists($k, $json) && is_numeric($json[$k])) {
                return (int) $json[$k];
            }
        }

        // Some APIs nest under errors
        $errors = $json['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($candidates as $k) {
                if (array_key_exists($k, $errors) && is_numeric($errors[$k])) {
                    return (int) $errors[$k];
                }
            }
        }

        return null;
    }

    private function extractErrorMessage($json): ?string
    {
        if (!is_array($json)) return null;

        foreach (['message', 'error', 'msg'] as $k) {
            $v = $json[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        $errors = $json['errors'] ?? null;
        if (is_array($errors)) {
            foreach (['message', 'error', 'msg'] as $k) {
                $v = $errors[$k] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return null;
    }
}
