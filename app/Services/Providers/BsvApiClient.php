<?php

namespace App\Services\Providers;

use App\Models\ProviderIntegration;
use Illuminate\Support\Facades\Http;

final class BsvApiClient
{
    public function profile(ProviderIntegration $integration): array
    {
        return $this->get($integration, '/api/profile');
    }

    public function products(ProviderIntegration $integration): array
    {
        return $this->get($integration, '/api/products');
    }

    private function get(ProviderIntegration $integration, string $path, array $query = []): array
    {
        $base = $this->resolveBaseUrl($integration);
        if ($base === '') {
            return [
                'ok' => false,
                'http_status' => null,
                'json' => null,
                'raw' => null,
                'error' => 'Missing base_url in integration credentials/meta',
            ];
        }

        $headers = [];
        $creds = $integration->credentials;
        if (is_array($creds) && !empty($creds['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . (string) $creds['api_key'];
        }

        try {
            $resp = Http::timeout(20)
                ->retry(1, 200)
                ->withHeaders($headers)
                ->acceptJson()
                ->get(rtrim($base, '/') . '/' . ltrim($path, '/'), $query);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'http_status' => null,
                'json' => null,
                'raw' => null,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'ok' => $resp->successful(),
            'http_status' => $resp->status(),
            'json' => $resp->json(),
            'raw' => $resp->body(),
            'error' => $resp->successful() ? null : ($resp->reason() ?: 'Request failed'),
        ];
    }

    private function resolveBaseUrl(ProviderIntegration $integration): string
    {
        $creds = $integration->credentials;
        $meta = $integration->meta;

        $base = '';
        if (is_array($creds) && !empty($creds['base_url'])) {
            $base = trim((string) $creds['base_url']);
        } elseif (is_array($meta) && !empty($meta['base_url'])) {
            $base = trim((string) $meta['base_url']);
        }

        if ($base !== '' && !str_starts_with($base, 'http://') && !str_starts_with($base, 'https://')) {
            $base = 'https://' . ltrim($base, '/');
        }

        return $base;
    }
}
