<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\FulfillmentRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class FulfillmentService
{
    public function __construct(
        private FulfillmentClient $client,
    ) {}

    public function deliver(Order $order): Delivery
    {
        // Idempotency: إذا already delivered
        $delivery = $order->delivery()->firstOrCreate([], [
            'status' => 'pending',
            'payload' => null,
            'delivered_at' => null,
        ]);

        if ($delivery->status === 'delivered') {
            return $delivery;
        }

        // إعادة ضبط بسيطة لمحاولة جديدة
        if ($delivery->status !== 'delivered') {
            $delivery->status = 'pending';
            $delivery->save();
        }

        // لازم يكون Paid قبل التسليم
        if ($order->payment_status !== 'paid') {
            $delivery->status = 'failed';
            $delivery->payload = ['error' => 'Order not paid'];
            $delivery->save();
            return $delivery;
        }

        // نفترض order فيه item واحد حالياً
        $item = $order->items()->first();
        if (!$item) {
            $delivery->status = 'failed';
            $delivery->payload = ['error' => 'No order items'];
            $delivery->save();
            return $delivery;
        }

        /** @var Product $product */
        $product = $item->product()->first();
        if (!$product) {
            $delivery->status = 'failed';
            $delivery->payload = ['error' => 'Product not found'];
            $delivery->save();
            return $delivery;
        }

        $provider = (string) ($product->provider_code ?? 'unknown');
        $type = (string) ($product->fulfillment_type ?? 'api');

        // payload قياسي يطلع للمزود
        $payload = [
            'order_id' => (string) $order->id,
            'currency' => (string) $order->currency,
            'amount_minor' => (int) $order->total_amount_minor,
            'provider_code' => $provider,
            'product_id' => (int) $product->id,
            'package_id' => $item->package_id ? (int) $item->package_id : null,
            'quantity' => (int) $item->quantity,
            'metadata' => $item->metadata ?? [],
        ];

        // endpoint حسب المنتج (من config) أو fallback
        $path = $product->fulfillment_config['path'] ?? '/fulfill';

        // إذا نوع التسليم ليس api (مستقبلاً ممكن driver ثاني)
        if ($type !== 'api') {
            $fr = FulfillmentRequest::create([
                'order_id' => $order->id,
                'provider' => $provider,
                'status' => 'failed',
                'http_status' => null,
                'request_payload' => $payload,
                'response_payload' => null,
                'error_message' => "Fulfillment type not supported: {$type}",
            ]);

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $provider,
                'error' => "Fulfillment type not supported: {$type}",
                'fulfillment_request_id' => $fr->id,
            ];
            $delivery->save();

            return $delivery;
        }

        // Status tracking: delivering
        if (!in_array($order->status, ['delivered'], true)) {
            $order->status = 'delivering';
            $order->save();
        }

        // سجل محاولة التسليم في DB
        $fr = FulfillmentRequest::create([
            'order_id' => $order->id,
            'provider' => $provider,
            'status' => 'pending',
            'http_status' => null,
            'request_payload' => $payload,
            'response_payload' => null,
            'error_message' => null,
        ]);

        // مرر request_id للمزوّد
        $rid = null;
        try {
            $rid = request()->attributes->get('request_id') ?? request()->header('X-Request-Id');
        } catch (\Throwable $e) {
            $rid = null;
        }

        $headers = [];
        if ($rid) {
            $headers['X-Request-Id'] = (string) $rid;
        }

        Log::info('fulfillment.attempt', [
            'request_id' => $rid,
            'order_id' => $order->id,
            'provider' => $provider,
            'path' => $path,
            'fulfillment_request_id' => $fr->id,
        ]);

        try {
            $result = $this->client->post($path, $payload, $headers);

            $fr->http_status = $result['http_status'] ?? null;
            $fr->response_payload = $result['json'] ?? ['raw' => ($result['raw'] ?? null)];
            $fr->request_id = $result['provider_request_id'] ?? null;

            if (!empty($result['ok'])) {
                $fr->status = 'success';
                $fr->save();

                $delivery->status = 'delivered';
                $delivery->payload = [
                    'provider' => $provider,
                    'http_status' => $result['http_status'],
                    'response' => $result['json'] ?? $result['raw'],
                    'fulfillment_request_id' => $fr->id,
                ];
                $delivery->delivered_at = now();
                $delivery->save();

                $order->status = 'delivered';
                $order->save();

                Log::info('fulfillment.success', [
                    'request_id' => $rid,
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'fulfillment_request_id' => $fr->id,
                ]);

                return $delivery;
            }

            $fr->status = 'failed';
            $fr->save();

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $provider,
                'http_status' => $result['http_status'],
                'response' => $result['json'] ?? $result['raw'],
                'fulfillment_request_id' => $fr->id,
            ];
            $delivery->save();

            // رجّع order إلى paid (مدفوع لكن لم يُسلّم)
            $order->status = 'paid';
            $order->save();

            Log::warning('fulfillment.failed', [
                'request_id' => $rid,
                'order_id' => $order->id,
                'provider' => $provider,
                'http_status' => $result['http_status'] ?? null,
                'fulfillment_request_id' => $fr->id,
            ]);

            return $delivery;

        } catch (\Throwable $e) {
            $fr->status = 'failed';
            $fr->error_message = $e->getMessage();
            $fr->save();

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'fulfillment_request_id' => $fr->id,
            ];
            $delivery->save();

            $order->status = 'paid';
            $order->save();

            Log::error('fulfillment.exception', [
                'request_id' => $rid,
                'order_id' => $order->id,
                'provider' => $provider,
                'fulfillment_request_id' => $fr->id,
                'error' => $e->getMessage(),
            ]);

            return $delivery;
        }
    }
}
