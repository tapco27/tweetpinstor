<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FulfillmentClient
{
    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->request('GET', $path, $query, $headers);
    }

    public function post(string $path, array $payload, array $headers = []): array
    {
        return $this->request('POST', $path, $payload, $headers);
    }

    private function request(string $method, string $path, array $data, array $headers = []): array
    {
        $base = rtrim((string) config('services.fulfillment.base_url'), '/');
        $url  = $base . '/' . ltrim($path, '/');

        // Tweet-Pin auth: api-token: YOUR_API_TOKEN
        $token = (string) config('services.fulfillment.token');
        if ($token !== '' && !$this->hasHeader($headers, 'api-token')) {
            $headers = array_merge(['api-token' => $token], $headers);
        }

        $http = Http::timeout((int) config('services.fulfillment.timeout'))
            ->retry((int) config('services.fulfillment.retries'), 200)
            ->withHeaders($headers)
            ->acceptJson();

        $method = strtoupper($method);

        if ($method === 'GET') {
            $resp = $http->get($url, $data);
        } else {
            $resp = $http->asJson()->post($url, $data);
        }

        $providerRequestId =
            $resp->header('X-Request-Id')
            ?? $resp->header('Request-Id')
            ?? $resp->header('X-Provider-Request-Id');

        return [
            'ok' => $resp->successful(),
            'http_status' => $resp->status(),
            'json' => $resp->json(),
            'raw' => $resp->body(),
            'provider_request_id' => $providerRequestId,
        ];
    }

    private function hasHeader(array $headers, string $name): bool
    {
        $name = strtolower($name);
        foreach ($headers as $key => $_) {
            if (strtolower((string) $key) === $name) {
                return true;
            }
        }
        return false;
    }
}
