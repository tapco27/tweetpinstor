<?php

namespace App\Services;

use App\Jobs\CheckTweetPinOrderJob;
use App\Jobs\DeliverOrderJob;
use App\Models\Delivery;
use App\Models\FulfillmentRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductProviderSlot;
use App\Models\ProviderIntegration;
use App\Services\Providers\TweetPinApiClient;
use App\Support\ProviderTemplates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FulfillmentService
{
    public function __construct(
        private FulfillmentClient $client,
        private DigitalPinsFulfillmentService $digitalPins,
        private TweetPinApiClient $tweetPin,
        private WalletService $wallets,
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

        // Payload قياسي لكل أنواع التسليم
        $basePayload = [
            'order_id' => (string) $order->id,
            'order_uuid' => (string) ($order->order_uuid ?? ''),
            'currency' => (string) $order->currency,
            'amount_minor' => (int) $order->total_amount_minor,
            'product_id' => (int) $product->id,
            'package_id' => $item->package_id ? (int) $item->package_id : null,
            'quantity' => (int) $item->quantity,
            'metadata' => $item->metadata ?? [],
        ];

        // ✅ Phase 5: Provider Slots (slot 1/2)
        $slots = $product->providerSlots()
            ->with('integration')
            ->where('is_active', true)
            ->orderBy('slot')
            ->get();

        if ($slots->count() > 0) {
            return $this->deliverUsingProviderSlots($order, $product, $delivery, $slots->all(), $basePayload);
        }

        // 🔙 Backward-compatible legacy behavior (product.fulfillment_type/provider_code)
        return $this->deliverUsingLegacyProductFields($order, $product, $delivery, $basePayload);
    }

    /**
     * Provider slots delivery:
     * - slot 1 primary
     * - slot 2 fallback
     */
    private function deliverUsingProviderSlots(
        Order $order,
        Product $product,
        Delivery $delivery,
        array $slots,
        array $basePayload
    ): Delivery {

        // Status tracking: delivering
        if (!in_array($order->status, ['delivered'], true)) {
            $order->status = 'delivering';
            $order->save();
        }

        $attempts = [];
        foreach ($slots as $s) {
            if (!$s instanceof ProductProviderSlot) continue;
            $slotNo = (int) ($s->slot ?? 0);
            if (!in_array($slotNo, [1, 2], true)) continue;

            $integration = $s->integration;
            if (!$integration instanceof ProviderIntegration) continue;
            if (!$integration->is_active) continue;

            $attempts[$slotNo] = [$s, $integration];
        }

        ksort($attempts);

        if (count($attempts) === 0) {
            // No usable integrations, fallback to legacy
            return $this->deliverUsingLegacyProductFields($order, $product, $delivery, $basePayload);
        }

        $errors = [];

        foreach ($attempts as $slotNo => [$slotRow, $integration]) {

            // Prepare delivery for a new attempt
            $delivery = $this->resetDeliveryForRetry($order, $delivery);

            $providerCode = (string) ($integration->template_code ?? 'unknown');
            $providerType = ProviderTemplates::typeFor($providerCode, 'api');

            $payload = $basePayload;
            $payload['provider_code'] = $providerCode;
            $payload['provider_integration_id'] = (int) $integration->id;

            // Only server-side; forwarded to fulfillment service if needed.
            $creds = $integration->credentials;
            if (is_array($creds) && count($creds) > 0) {
                $payload['provider_credentials'] = $creds;
            }

            // Per-slot overrides
            $overridePath = is_array($slotRow->override_config ?? null)
                ? ($slotRow->override_config['path'] ?? null)
                : null;

            if ($providerType === 'digital_pins') {
                $res = $this->attemptDigitalPins($order, $product, $delivery, $payload, $providerCode, (int) $integration->id, (int) $slotNo);

                if ((string) $res->status === 'delivered') {
                    return $res;
                }

                $errors[] = [
                    'slot' => (int) $slotNo,
                    'provider_code' => $providerCode,
                    'type' => 'digital_pins',
                    'error' => $res->payload['error'] ?? 'digital pins delivery failed',
                ];

                continue;
            }

            // ✅ Tweet-Pin real provider
            if ($providerType === 'tweet_pin') {
                $res = $this->attemptTweetPin($order, $product, $delivery, $payload, $integration, is_array($slotRow->override_config ?? null) ? $slotRow->override_config : null, $slotNo);

                if ((string) $res->status === 'delivered') {
                    return $res;
                }

                // IMPORTANT: when provider returns "wait", do NOT try slot 2.
                if ((string) $res->status === 'pending') {
                    return $res;
                }

                $errors[] = [
                    'slot' => (int) $slotNo,
                    'provider_code' => $providerCode,
                    'type' => 'tweet_pin',
                    'error' => $res->payload['error'] ?? 'tweet-pin fulfillment failed',
                    'http_status' => $res->payload['http_status'] ?? null,
                ];

                continue;
            }

            // Default API
            $path = $overridePath
                ?? ($product->fulfillment_config['path'] ?? '/fulfill');

            $res = $this->attemptApiFulfillment($order, $delivery, $payload, $providerCode, (int) $integration->id, (int) $slotNo, $path);

            if ((string) $res->status === 'delivered') {
                return $res;
            }

            $errors[] = [
                'slot' => (int) $slotNo,
                'provider_code' => $providerCode,
                'type' => 'api',
                'error' => $res->payload['error'] ?? 'api fulfillment failed',
                'http_status' => $res->payload['http_status'] ?? null,
            ];
        }

        // All attempts failed
        $delivery->status = 'failed';
        $delivery->payload = [
            'error' => 'All provider slots failed',
            'provider_slots_errors' => $errors,
        ];
        $delivery->save();

        $order->status = 'failed';
        $order->save();

        // Optional: auto-refund wallet-paid orders on final failure
        $autoRefund = (bool) config('fulfillment.auto_refund_on_final_failure', false);
        if ($autoRefund && (string) ($order->payment_provider ?? '') === 'wallet') {
            try {
                $result = $this->wallets->refundWalletOrder((int) $order->id, 0, 'auto_refund_on_final_failure');
                $delivery->payload = array_merge((array) ($delivery->payload ?? []), [
                    'auto_refunded' => true,
                    'refund_tx_id' => $result['transaction']->id ?? null,
                ]);
                $delivery->save();
            } catch (\Throwable $e) {
                Log::warning('fulfillment.auto_refund.failed', [
                    'order_id' => (int) $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $delivery;
    }

    private function attemptDigitalPins(
        Order $order,
        Product $product,
        Delivery $delivery,
        array $payload,
        string $providerCode,
        int $integrationId,
        int $slotNo
    ): Delivery {

        $fr = FulfillmentRequest::create([
            'order_id' => $order->id,
            'provider' => $providerCode,
            'provider_integration_id' => $integrationId,
            'slot' => $slotNo,
            'status' => 'pending',
            'http_status' => null,
            'request_payload' => $payload,
            'response_payload' => null,
            'error_message' => null,
        ]);

        try {
            $res = $this->digitalPins->deliver($order, $product, $delivery);

            if ((string) $res->status === 'delivered') {
                $fr->status = 'success';
                $fr->response_payload = [
                    'type' => 'digital_pins',
                    'delivered' => true,
                    'product_id' => (int) $product->id,
                    'package_id' => $payload['package_id'] ?? null,
                    'quantity' => $payload['quantity'] ?? null,
                ];
                $fr->save();

                return $res;
            }

            $fr->status = 'failed';
            $fr->response_payload = [
                'type' => 'digital_pins',
                'delivered' => false,
                'product_id' => (int) $product->id,
                'package_id' => $payload['package_id'] ?? null,
                'quantity' => $payload['quantity'] ?? null,
            ];
            $fr->error_message = is_array($res->payload ?? null) ? ($res->payload['error'] ?? null) : null;
            $fr->save();

            return $res;

        } catch (\Throwable $e) {
            $fr->status = 'failed';
            $fr->error_message = $e->getMessage();
            $fr->save();

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $providerCode,
                'error' => $e->getMessage(),
                'fulfillment_request_id' => $fr->id,
            ];
            $delivery->save();

            $order->status = 'paid';
            $order->save();

            return $delivery;
        }
    }

    private function attemptApiFulfillment(
        Order $order,
        Delivery $delivery,
        array $payload,
        string $providerCode,
        int $integrationId,
        int $slotNo,
        string $path
    ): Delivery {

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

        // سجل محاولة التسليم في DB
        $fr = FulfillmentRequest::create([
            'order_id' => $order->id,
            'provider' => $providerCode,
            'provider_integration_id' => $integrationId,
            'slot' => $slotNo,
            'status' => 'pending',
            'http_status' => null,
            'request_payload' => $payload,
            'response_payload' => null,
            'error_message' => null,
        ]);

        Log::info('fulfillment.attempt', [
            'request_id' => $rid,
            'order_id' => $order->id,
            'provider' => $providerCode,
            'path' => $path,
            'slot' => $slotNo,
            'provider_integration_id' => $integrationId,
            'fulfillment_request_id' => $fr->id,
        ]);

        try {
            $integration = ProviderIntegration::query()->find($integrationId);
            $result = $this->postViaIntegration($integration, $path, $payload, $headers);

            $fr->http_status = $result['http_status'] ?? null;
            $fr->response_payload = $result['json'] ?? ['raw' => ($result['raw'] ?? null)];
            $fr->request_id = $result['provider_request_id'] ?? null;

            if (!empty($result['ok'])) {
                $fr->status = 'success';
                $fr->save();

                $delivery->status = 'delivered';
                $delivery->payload = [
                    'provider' => $providerCode,
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
                    'provider' => $providerCode,
                    'slot' => $slotNo,
                    'fulfillment_request_id' => $fr->id,
                ]);

                return $delivery;
            }

            $fr->status = 'failed';
            $fr->save();

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $providerCode,
                'http_status' => $result['http_status'],
                'response' => $result['json'] ?? $result['raw'],
                'fulfillment_request_id' => $fr->id,
                'error' => 'Provider returned non-ok response',
            ];
            $delivery->save();

            // رجّع order إلى paid (مدفوع لكن لم يُسلّم)
            $order->status = 'paid';
            $order->save();

            Log::warning('fulfillment.failed', [
                'request_id' => $rid,
                'order_id' => $order->id,
                'provider' => $providerCode,
                'slot' => $slotNo,
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
                'provider' => $providerCode,
                'error' => $e->getMessage(),
                'fulfillment_request_id' => $fr->id,
            ];
            $delivery->save();

            $order->status = 'paid';
            $order->save();

            Log::error('fulfillment.exception', [
                'request_id' => $rid,
                'order_id' => $order->id,
                'provider' => $providerCode,
                'slot' => $slotNo,
                'fulfillment_request_id' => $fr->id,
                'error' => $e->getMessage(),
            ]);

            return $delivery;
        }
    }

    private function deliverUsingLegacyProductFields(Order $order, Product $product, Delivery $delivery, array $basePayload): Delivery
    {
        $provider = (string) ($product->provider_code ?? 'unknown');
        $type = (string) ($product->fulfillment_type ?? 'api');

        // Internal stock-based fulfillment
        if ($type === 'digital_pins') {
            // Status tracking: delivering
            if (!in_array($order->status, ['delivered'], true)) {
                $order->status = 'delivering';
                $order->save();
            }

            return $this->digitalPins->deliver($order, $product, $delivery);
        }

        // Legacy Tweet-Pin path: try to use the first active ProviderIntegration(template_code=tweet_pin)
        // (backward-compatible: tweetpin / tweet-pin)
        if (
            $type === 'tweet_pin'
            || $type === 'tweetpin'
            || ($type === 'api' && in_array($provider, ['tweet_pin', 'tweetpin', 'tweet-pin'], true))
        ) {
            $integrationId = null;
            if (is_array($product->fulfillment_config ?? null)) {
                $integrationId = $product->fulfillment_config['provider_integration_id'] ?? null;
            }

            /** @var ProviderIntegration|null $integration */
            $integration = null;
            if ($integrationId) {
                $integration = ProviderIntegration::query()->find((int) $integrationId);
            }
            if (!$integration) {
                $integration = ProviderIntegration::query()
                    ->where('template_code', 'tweet_pin')
                    ->where('is_active', true)
                    ->orderByDesc('id')
                    ->first();
            }

            if (!$integration) {
                $delivery->status = 'failed';
                $delivery->payload = ['error' => 'Tweet-Pin integration not configured'];
                $delivery->save();
                return $delivery;
            }

            // Status tracking: delivering
            if (!in_array($order->status, ['delivered'], true)) {
                $order->status = 'delivering';
                $order->save();
            }

            $payload = $basePayload;
            $payload['provider_code'] = 'tweet_pin';

            $override = is_array($product->fulfillment_config ?? null) ? $product->fulfillment_config : null;
            return $this->attemptTweetPin($order, $product, $delivery, $payload, $integration, $override, null);
        }

        // payload قياسي يطلع للمزود
        $payload = $basePayload;
        $payload['provider_code'] = $provider;

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
            $integration = ProviderIntegration::query()->find($integrationId);
            $result = $this->postViaIntegration($integration, $path, $payload, $headers);

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


    /**
     * Post using integration-specific base_url and credentials when available.
     * Falls back to FulfillmentClient config behavior for backward compatibility.
     */
    private function postViaIntegration(?ProviderIntegration $integration, string $path, array $payload, array $headers = []): array
    {
        if (!$integration) {
            return $this->client->post($path, $payload, $headers);
        }

        $creds = $integration->credentials;
        $meta = $integration->meta;

        $base = '';
        if (is_array($creds) && !empty($creds['base_url'])) {
            $base = trim((string) $creds['base_url']);
        } elseif (is_array($meta) && !empty($meta['base_url'])) {
            $base = trim((string) $meta['base_url']);
        }

        if ($base === '') {
            return $this->client->post($path, $payload, $headers);
        }

        if (!str_starts_with($base, 'http://') && !str_starts_with($base, 'https://')) {
            $base = 'https://' . ltrim($base, '/');
        }

        $url = rtrim($base, '/') . '/' . ltrim($path, '/');

        $timeout = 20;
        $retries = 1;
        $http = Http::timeout($timeout)
            ->retry($retries, 200)
            ->withHeaders($headers)
            ->acceptJson();

        if (is_array($creds) && !empty($creds['api_key']) && !array_key_exists('Authorization', $headers)) {
            $http = $http->withToken((string) $creds['api_key']);
        }

        if (is_array($creds) && !empty($creds['username']) && !empty($creds['password'])) {
            $http = $http->withBasicAuth((string) $creds['username'], (string) $creds['password']);
        }

        try {
            $resp = $http->asJson()->post($url, $payload);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'http_status' => null,
                'json' => null,
                'raw' => null,
                'provider_request_id' => null,
                'error' => $e->getMessage(),
            ];
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

    /**
     * Reset delivery + order to allow next provider attempt.
     */
    private function resetDeliveryForRetry(Order $order, Delivery $delivery): Delivery
    {
        if ((string) $delivery->status !== 'delivered') {
            $delivery->status = 'pending';
            $delivery->payload = null;
            $delivery->delivered_at = null;
            $delivery->save();
        }

        if (!in_array($order->status, ['delivered'], true)) {
            $order->status = 'delivering';
            $order->save();
        }

        return $delivery->fresh();
    }

    /**
     * Tweet-Pin fulfillment attempt.
     *
     * Notes:
     * - newOrder is GET and requires order_uuid for idempotency
     * - provider status: accept | reject | wait
     * - when status=wait we keep delivery pending and schedule a check job
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $overrideConfig
     */
    private function attemptTweetPin(
        Order $order,
        Product $product,
        Delivery $delivery,
        array $payload,
        ProviderIntegration $integration,
        ?array $overrideConfig,
        ?int $slotNo
    ): Delivery {
        $providerCode = 'tweet_pin';
        $integrationId = (int) $integration->id;

        $fr = FulfillmentRequest::create([
            'order_id' => $order->id,
            'provider' => $providerCode,
            'provider_integration_id' => $integrationId,
            'slot' => $slotNo,
            'status' => 'pending',
            'http_status' => null,
            'request_payload' => $payload,
            'response_payload' => null,
            'error_message' => null,
        ]);

        // Build merged config (product.fulfillment_config + slot.override_config)
        $cfg = [];
        if (is_array($product->fulfillment_config ?? null)) {
            $cfg = $product->fulfillment_config;
        }
        if (is_array($overrideConfig)) {
            $cfg = array_merge($cfg, $overrideConfig);
        }

        // Resolve provider product id
        $packageId = isset($payload['package_id']) ? (int) ($payload['package_id'] ?? 0) : null;
        $providerProductId = $this->resolveTweetPinProviderProductId($product, $packageId, $cfg);

        if (!$providerProductId) {
            $fr->status = 'failed';
            $fr->error_message = 'Missing Tweet-Pin provider product id mapping';
            $fr->save();

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $providerCode,
                'error' => $fr->error_message,
                'fulfillment_request_id' => (int) $fr->id,
            ];
            $delivery->save();

            $order->status = 'paid';
            $order->save();

            return $delivery;
        }

        // qty
        $qty = (int) ($payload['quantity'] ?? 1);
        if ((string) $product->product_type === 'fixed_package') {
            // Default: package products usually use qty=1
            $qty = 1;

            // Special case: amount-type provider where each local package maps to a provider quantity.
            // Config key: tweetpin_package_qty_map (package_id => qty)
            $qmap = $this->extractFirstArray($cfg, ['tweetpin_package_qty_map', 'package_qty_map']);
            if (is_array($qmap) && $packageId) {
                $key = (string) $packageId;
                $val = null;
                if (array_key_exists($key, $qmap)) {
                    $val = $qmap[$key];
                } elseif (array_key_exists($packageId, $qmap)) {
                    $val = $qmap[$packageId];
                }
                if (is_numeric($val) && (int) $val > 0) {
                    $qty = (int) $val;
                }
            }
        }

        // order_uuid
        $orderUuid = (string) ($payload['order_uuid'] ?? $order->order_uuid ?? '');
        if ($orderUuid === '') {
            $orderUuid = (string) $order->id;
        }

        // params mapping
        $paramMap = $this->extractFirstArray($cfg, ['params_map', 'param_map', 'tweetpin_param_map']);
        $staticParams = $this->extractFirstArray($cfg, ['static_params']);

        $meta = $payload['metadata'] ?? [];
        $meta = is_array($meta) ? $meta : [];

        $params = [];
        if (is_array($staticParams)) {
            foreach ($staticParams as $k => $v) {
                if (!is_string($k) || trim($k) === '') continue;
                if (is_scalar($v) || $v === null) {
                    $params[trim($k)] = $v;
                }
            }
        }

        foreach ($meta as $k => $v) {
            if (!is_string($k) || trim($k) === '') continue;
            if (!(is_scalar($v) || $v === null)) continue;

            $kk = $k;
            if (is_array($paramMap) && array_key_exists($k, $paramMap)) {
                $mapped = $paramMap[$k];
                if (is_string($mapped) && trim($mapped) !== '') {
                    $kk = trim($mapped);
                }
            }

            $params[$kk] = $v;
        }

        try {
            $resp = $this->tweetPin->newOrder($integration, (int) $providerProductId, $qty, $params, $orderUuid);

            $fr->http_status = $resp['http_status'] ?? null;
            $fr->response_payload = $resp['json'] ?? ['raw' => ($resp['raw'] ?? null)];

            $json = $resp['json'] ?? null;
            $providerStatus = $this->extractTweetPinStatus($json);
            $providerOrderId = $this->extractTweetPinOrderId($json);

            if ($providerOrderId !== '') {
                $fr->request_id = $providerOrderId;
            }

            if (empty($resp['ok'])) {
                $fr->error_message = (string) ($resp['error'] ?? 'Tweet-Pin request failed');

                // Temporary errors that should be retried (e.g. 111: Try again after 1 minute)
                $errCode = (int) ($resp['error_code'] ?? 0);
                if (in_array($errCode, [111, 130], true)) {
                    $fr->status = 'pending';
                    $fr->save();

                    $delivery->status = 'pending';
                    $delivery->payload = [
                        'provider' => $providerCode,
                        'http_status' => $resp['http_status'] ?? null,
                        'response' => $resp['json'] ?? $resp['raw'] ?? null,
                        'fulfillment_request_id' => (int) $fr->id,
                        'provider_error_code' => $errCode,
                        'error' => $fr->error_message,
                        'retry_in_seconds' => 60,
                    ];
                    $delivery->save();

                    if ((string) $order->status !== 'delivered') {
                        $order->status = 'delivering';
                        $order->save();
                    }

                    DeliverOrderJob::dispatch((int) $order->id)->delay(now()->addSeconds(60));
                    return $delivery;
                }

                $fr->status = 'failed';
                $fr->save();

                $delivery->status = 'failed';
                $delivery->payload = [
                    'provider' => $providerCode,
                    'http_status' => $resp['http_status'] ?? null,
                    'response' => $resp['json'] ?? $resp['raw'] ?? null,
                    'fulfillment_request_id' => (int) $fr->id,
                    'provider_error_code' => $errCode,
                    'error' => $fr->error_message,
                ];
                $delivery->save();

                $order->status = 'paid';
                $order->save();

                return $delivery;
            }

            $providerStatus = $providerStatus ?: 'unknown';

            // accept
            if ($providerStatus === 'accept') {
                $fr->status = 'success';
                $fr->save();

                $delivery->status = 'delivered';
                $delivery->payload = [
                    'provider' => $providerCode,
                    'provider_order_id' => $providerOrderId,
                    'provider_status' => $providerStatus,
                    'http_status' => $resp['http_status'] ?? null,
                    'response' => $resp['json'] ?? $resp['raw'] ?? null,
                    'fulfillment_request_id' => (int) $fr->id,
                ];
                $delivery->delivered_at = now();
                $delivery->save();

                $order->status = 'delivered';
                $order->save();

                return $delivery;
            }

            // wait
            if ($providerStatus === 'wait') {
                // keep pending
                $fr->status = 'pending';
                $fr->save();

                $delivery->status = 'pending';
                $delivery->payload = [
                    'provider' => $providerCode,
                    'provider_order_id' => $providerOrderId,
                    'provider_status' => $providerStatus,
                    'http_status' => $resp['http_status'] ?? null,
                    'response' => $resp['json'] ?? $resp['raw'] ?? null,
                    'fulfillment_request_id' => (int) $fr->id,
                    'next_check_in_seconds' => 20,
                ];
                $delivery->save();

                // Keep order delivering
                if ((string) $order->status !== 'delivered') {
                    $order->status = 'delivering';
                    $order->save();
                }

                // Poll later
                CheckTweetPinOrderJob::dispatch((int) $order->id, (int) $fr->id)
                    ->delay(now()->addSeconds(20));

                return $delivery;
            }

            // reject / unknown
            $fr->status = 'failed';
            $fr->error_message = 'Tweet-Pin status: ' . $providerStatus;
            $fr->save();

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $providerCode,
                'provider_order_id' => $providerOrderId,
                'provider_status' => $providerStatus,
                'http_status' => $resp['http_status'] ?? null,
                'response' => $resp['json'] ?? $resp['raw'] ?? null,
                'fulfillment_request_id' => (int) $fr->id,
                'error' => $fr->error_message,
            ];
            $delivery->save();

            $order->status = 'paid';
            $order->save();

            return $delivery;

        } catch (\Throwable $e) {
            $fr->status = 'failed';
            $fr->error_message = $e->getMessage();
            $fr->save();

            $delivery->status = 'failed';
            $delivery->payload = [
                'provider' => $providerCode,
                'error' => $e->getMessage(),
                'fulfillment_request_id' => (int) $fr->id,
            ];
            $delivery->save();

            $order->status = 'paid';
            $order->save();

            return $delivery;
        }
    }

    private function extractTweetPinStatus($json): ?string
    {
        if (!is_array($json)) return null;
        $data = $json['data'] ?? null;

        $nested = null;
        if (is_array($data)) {
            $nested = $data['status'] ?? null;
        }

        $st = $nested ?? ($json['status'] ?? null);
        $st = is_string($st) ? strtolower(trim($st)) : null;
        if ($st === 'ok') {
            // root status OK, but we need nested data.status
            $st2 = is_array($data) ? ($data['status'] ?? null) : null;
            $st2 = is_string($st2) ? strtolower(trim($st2)) : null;
            return $st2;
        }
        return $st;
    }

    private function extractTweetPinOrderId($json): string
    {
        if (!is_array($json)) return '';
        $data = $json['data'] ?? null;
        $nested = null;
        if (is_array($data)) {
            $nested = $data['order_id'] ?? null;
        }
        $id = $nested ?? ($json['order_id'] ?? $json['id'] ?? null);
        return is_string($id) ? trim($id) : '';
    }

    /**
     * Resolve Tweet-Pin provider product id.
     *
     * Config supported keys:
     * - provider_product_id / tweetpin_product_id / remote_product_id
     * - tweetpin_package_map / package_map (map of local package_id => provider_product_id)
     */
    private function resolveTweetPinProviderProductId(Product $product, ?int $packageId, array $cfg): ?int
    {
        $productType = (string) ($product->product_type ?? '');

        // fixed packages: use package map first
        if ($productType === 'fixed_package') {
            $map = $this->extractFirstArray($cfg, ['tweetpin_package_map', 'package_map', 'remote_package_map']);
            if (is_array($map) && $packageId) {
                $key = (string) $packageId;
                if (array_key_exists($key, $map)) {
                    $val = $map[$key];
                    if (is_numeric($val)) {
                        return (int) $val;
                    }
                }
                if (array_key_exists($packageId, $map)) {
                    $val = $map[$packageId];
                    if (is_numeric($val)) {
                        return (int) $val;
                    }
                }
            }
        }

        // fallback: direct provider product id
        $pid = $cfg['provider_product_id']
            ?? $cfg['tweetpin_product_id']
            ?? $cfg['remote_product_id']
            ?? null;

        if (is_numeric($pid)) {
            return (int) $pid;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array<int,string> $keys
     * @return array<string,mixed>|null
     */
    private function extractFirstArray(array $cfg, array $keys): ?array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $cfg) && is_array($cfg[$k])) {
                return $cfg[$k];
            }
        }
        return null;
    }
}
