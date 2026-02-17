<?php

namespace App\Jobs;

use App\Models\Delivery;
use App\Models\FulfillmentRequest;
use App\Models\Order;
use App\Models\ProviderIntegration;
use App\Jobs\DeliverOrderJob;
use App\Services\Providers\TweetPinApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Poll Tweet-Pin order status when provider returns "wait".
 */
class CheckTweetPinOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $timeout = 30;

    public function __construct(
        public int $orderId,
        public int $fulfillmentRequestId,
    ) {}

    public function handle(TweetPinApiClient $tweetPin): void
    {
        /** @var FulfillmentRequest|null $fr */
        $fr = FulfillmentRequest::query()
            ->with('integration')
            ->find($this->fulfillmentRequestId);

        if (!$fr) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::query()
            ->with(['items.product.providerSlots.integration', 'items.package', 'delivery'])
            ->find($this->orderId);

        if (!$order) {
            return;
        }

        // If already delivered, stop polling.
        if ((string) ($order->status ?? '') === 'delivered') {
            return;
        }

        /** @var ProviderIntegration|null $integration */
        $integration = $fr->integration;

        if (!$integration) {
            // Can't check without integration
            $this->markFailed($order, $fr, 'Tweet-Pin integration missing');
            return;
        }

        $providerOrderId = is_string($fr->request_id) ? trim($fr->request_id) : '';
        $orderUuid = is_string($order->order_uuid ?? null) ? trim((string) $order->order_uuid) : '';

        $ref = $providerOrderId !== '' ? $providerOrderId : $orderUuid;
        $useUuid = $providerOrderId === '' && $orderUuid !== '';

        if ($ref === '') {
            $this->markFailed($order, $fr, 'Missing provider order id and order_uuid');
            return;
        }

        $resp = $tweetPin->check($integration, [$ref], $useUuid);

        if (empty($resp['ok'])) {
            // Temporary failure: keep pending and re-schedule
            $this->reschedule($order, $fr, $resp, 30);
            return;
        }

        $json = $resp['json'] ?? null;
        $status = $this->extractStatus($json);

        if ($status === 'accept') {
            $this->markDelivered($order, $fr, $resp, $providerOrderId ?: $ref, $status);
            return;
        }

        if ($status === 'wait') {
            $this->reschedule($order, $fr, $resp, 20, $providerOrderId ?: $ref, $status);
            return;
        }

        // reject / unknown
        $this->markFailed($order, $fr, 'Tweet-Pin status: ' . ($status ?: 'unknown'), $resp, $providerOrderId ?: $ref, $status);
    }

    private function extractStatus($json): string
    {
        if (!is_array($json)) {
            return '';
        }

        $data = $json['data'] ?? null;

        // Expected: data[0].status
        if (is_array($data) && array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            $st = $data[0]['status'] ?? null;
            $st = is_string($st) ? strtolower(trim($st)) : '';
            return $st;
        }

        // Fallbacks
        $st = $json['status'] ?? null;
        $st = is_string($st) ? strtolower(trim($st)) : '';
        return $st;
    }

    private function ensureDelivery(Order $order): Delivery
    {
        return $order->delivery()->firstOrCreate([], [
            'status' => 'pending',
            'payload' => null,
            'delivered_at' => null,
        ]);
    }

    private function markDelivered(Order $order, FulfillmentRequest $fr, array $resp, string $providerOrderId, string $providerStatus): void
    {
        $fr->status = 'success';
        $fr->http_status = $resp['http_status'] ?? $fr->http_status;
        $fr->response_payload = $resp['json'] ?? ['raw' => ($resp['raw'] ?? null)];
        $fr->request_id = $providerOrderId;
        $fr->error_message = null;
        $fr->save();

        $delivery = $this->ensureDelivery($order);
        $delivery->status = 'delivered';
        $delivery->payload = [
            'provider' => 'tweet_pin',
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
    }

    private function markFailed(Order $order, FulfillmentRequest $fr, string $message, ?array $resp = null, ?string $providerOrderId = null, ?string $providerStatus = null): void
    {
        $fr->status = 'failed';
        $fr->error_message = $message;
        if (is_array($resp)) {
            $fr->http_status = $resp['http_status'] ?? $fr->http_status;
            $fr->response_payload = $resp['json'] ?? ['raw' => ($resp['raw'] ?? null)];
        }
        if (is_string($providerOrderId) && trim($providerOrderId) !== '') {
            $fr->request_id = trim($providerOrderId);
        }
        $fr->save();

        $delivery = $this->ensureDelivery($order);
        $delivery->status = 'failed';
        $delivery->payload = [
            'provider' => 'tweet_pin',
            'provider_order_id' => $providerOrderId,
            'provider_status' => $providerStatus,
            'http_status' => $resp['http_status'] ?? null,
            'response' => $resp['json'] ?? $resp['raw'] ?? null,
            'fulfillment_request_id' => (int) $fr->id,
            'error' => $message,
        ];
        $delivery->save();

        // Return order back to paid to allow retry/refund decision.
        if ((string) ($order->status ?? '') !== 'delivered') {
            $order->status = 'paid';
            $order->save();
        }

        // ✅ Phase 8: إذا Tweet-Pin كان slot 1 و فشل بعد wait، جرّب slot 2 تلقائياً (fallback)
        // الهدف: ما نخلي المستخدم عالق إذا عندك مزوّد بديل.
        $this->maybeDispatchFallbackDelivery($order, $fr);

        Log::warning('tweetpin.check.failed', [
            'order_id' => (int) $order->id,
            'fulfillment_request_id' => (int) $fr->id,
            'error' => $message,
        ]);
    }

    private function maybeDispatchFallbackDelivery(Order $order, FulfillmentRequest $fr): void
    {
        // Only if still not delivered
        if ((string) ($order->status ?? '') === 'delivered') {
            return;
        }

        $currentSlot = (int) ($fr->slot ?? 0);
        if (!in_array($currentSlot, [1, 2], true)) {
            return;
        }

        // Only fallback from slot 1 -> slot 2
        if ($currentSlot !== 1) {
            return;
        }

        $item = $order->items->first();
        if (!$item || !$item->product) {
            return;
        }

        $product = $item->product;

        // If slot 2 is not configured/active => no fallback
        $slot2 = null;
        if ($product->relationLoaded('providerSlots') && $product->providerSlots) {
            foreach ($product->providerSlots as $s) {
                $sn = (int) ($s->slot ?? 0);
                if ($sn !== 2) continue;
                if ((bool) ($s->is_active ?? false) !== true) continue;
                if (!isset($s->integration) || !$s->integration) continue;
                if ((bool) ($s->integration->is_active ?? false) !== true) continue;
                $slot2 = $s;
                break;
            }
        }

        if (!$slot2) {
            return;
        }

        // Prevent endless loops: if slot 2 already attempted for this order, do nothing.
        $alreadyTriedSlot2 = FulfillmentRequest::query()
            ->where('order_id', (int) $order->id)
            ->where('slot', 2)
            ->exists();

        if ($alreadyTriedSlot2) {
            return;
        }

        // Dispatch delivery job to try provider slots (it will attempt slot 1 first, then slot 2)
        DeliverOrderJob::dispatch((int) $order->id)->delay(now()->addSeconds(1));
    }

    private function reschedule(Order $order, FulfillmentRequest $fr, array $resp, int $delaySeconds, ?string $providerOrderId = null, ?string $providerStatus = null): void
    {
        $delaySeconds = max(10, (int) $delaySeconds);

        $fr->status = 'pending';
        $fr->http_status = $resp['http_status'] ?? $fr->http_status;
        $fr->response_payload = $resp['json'] ?? ['raw' => ($resp['raw'] ?? null)];
        if (is_string($providerOrderId) && trim($providerOrderId) !== '') {
            $fr->request_id = trim($providerOrderId);
        }
        $fr->save();

        $delivery = $this->ensureDelivery($order);
        $delivery->status = 'pending';
        $delivery->payload = [
            'provider' => 'tweet_pin',
            'provider_order_id' => $providerOrderId,
            'provider_status' => $providerStatus,
            'http_status' => $resp['http_status'] ?? null,
            'response' => $resp['json'] ?? $resp['raw'] ?? null,
            'fulfillment_request_id' => (int) $fr->id,
            'next_check_in_seconds' => $delaySeconds,
            'error' => $resp['error'] ?? null,
            'provider_error_code' => $resp['error_code'] ?? null,
        ];
        $delivery->save();

        if ((string) ($order->status ?? '') !== 'delivered') {
            $order->status = 'delivering';
            $order->save();
        }

        // Schedule again
        self::dispatch((int) $order->id, (int) $fr->id)->delay(now()->addSeconds($delaySeconds));
    }
}
