<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FulfillmentClient
{
    public function post(string $path, array $payload, array $headers = []): array
    {
        $base = rtrim((string) config('services.fulfillment.base_url'), '/');
        $url = $base . '/' . ltrim($path, '/');

        $resp = Http::timeout((int) config('services.fulfillment.timeout'))
            ->retry((int) config('services.fulfillment.retries'), 200)
            ->withToken((string) config('services.fulfillment.token'))
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

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
}
