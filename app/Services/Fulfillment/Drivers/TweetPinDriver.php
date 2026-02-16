<?php

namespace App\Services\Fulfillment\Drivers;

use App\Models\Order;
use App\Services\Fulfillment\Contracts\FulfillmentDriver;
use App\Services\Fulfillment\DTO\FulfillmentResult;
use App\Services\FulfillmentClient;

final class TweetPinDriver implements FulfillmentDriver
{
    public function __construct(private FulfillmentClient $http) {}

    public function code(): string
    {
        return 'tweetpin';
    }

    public function place(Order $order): FulfillmentResult
    {
        $item = $order->items->first();
        $product = $item?->product;

        $cfg = (array) ($product?->fulfillment_config ?? []);
        $remoteProductId = $cfg['remote_product_id'] ?? null;

        if (!$remoteProductId) {
            return new FulfillmentResult('failed', null, 'Missing remote_product_id in fulfillment_config');
        }

        $paramMap = (array) ($cfg['param_map'] ?? []); // مثال: ['uid' => 'playerId']
        $meta = (array) ($item?->metadata ?? []);

        $query = [
            'qty' => (int) ($item?->quantity ?? 1),
            'order_uuid' => (string) $order->order_uuid,
        ];

        foreach ($meta as $k => $v) {
            $kk = $paramMap[$k] ?? $k;
            $query[$kk] = $v;
        }

        // TweetPin: GET /client/api/newOrder/{id}/params
        $resp = $this->http->get("/client/api/newOrder/{$remoteProductId}/params", $query);

        $json = (array) ($resp['json'] ?? []);
        $status = (string) ($json['status'] ?? $json['data']['status'] ?? '');

        $providerOrderId = (string) ($json['order_id'] ?? $json['data']['order_id'] ?? $json['id'] ?? '');

        if ($resp['ok'] && $status === 'accept') {
            return new FulfillmentResult('delivered', $providerOrderId, null, null, ['raw' => $json]);
        }

        if ($resp['ok'] && $status === 'wait') {
            return new FulfillmentResult('waiting', $providerOrderId, null, 30, ['raw' => $json]);
        }

        return new FulfillmentResult('failed', $providerOrderId, 'Provider rejected/failed', null, ['raw' => $json, 'http' => $resp]);
    }

    public function check(Order $order, string $providerOrderId): FulfillmentResult
    {
        // TweetPin: (افتراض) GET /client/api/check?orders[]=ID
        $resp = $this->http->get("/client/api/check", ['orders' => [$providerOrderId]]);

        $json = (array) ($resp['json'] ?? []);
        $status = (string) ($json['status'] ?? $json['data'][0]['status'] ?? '');

        if ($resp['ok'] && $status === 'accept') {
            return new FulfillmentResult('delivered', $providerOrderId, null, null, ['raw' => $json]);
        }

        if ($resp['ok'] && $status === 'wait') {
            return new FulfillmentResult('waiting', $providerOrderId, null, 45, ['raw' => $json]);
        }

        return new FulfillmentResult('failed', $providerOrderId, 'Check failed/rejected', null, ['raw' => $json, 'http' => $resp]);
    }
}
