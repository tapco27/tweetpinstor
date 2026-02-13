<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\FulfillmentService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StripeWebhookController extends Controller
{
  public function __construct(
    private StripeService $stripe,
    private FulfillmentService $fulfillment,
  ) {}

  /**
   * @unauthenticated
   */
  public function handle(Request $request)
  {
    $payload = $request->getContent();
    $sig = $request->header('Stripe-Signature', '');

    try {
      $event = $this->stripe->verifyWebhook($payload, $sig);
    } catch (\Throwable $e) {
      return response()->json(['message' => 'Invalid webhook'], 400);
    }

    $type = $event->type ?? null;
    $eventId = $event->id ?? null;

    if (!$eventId) return response()->json(['ok' => true]);

    if ($type === 'payment_intent.succeeded') {
      $pi = $event->data->object ?? null;
      $piId = $pi->id ?? null;

      if ($piId) {
        DB::transaction(function () use ($piId, $eventId) {
          $order = Order::query()
            ->where('stripe_payment_intent_id', $piId)
            ->lockForUpdate()
            ->first();

          if (!$order) return;

          // idempotency
          if ($order->stripe_latest_event_id === $eventId) return;
          $order->stripe_latest_event_id = $eventId;

          // Mark paid (both status + payment_status)
          if (!in_array($order->status, ['paid', 'delivered'], true)) {
            $order->status = 'paid';
          }

          // IMPORTANT: this fixes fulfillment blocking
          if ($order->payment_status !== 'paid') {
            $order->payment_status = 'paid';
          }

          // Set provider if not set
          if (empty($order->payment_provider)) {
            $order->payment_provider = 'stripe';
          }

          $order->save();

          // deliver only once and only if not delivered
          if (!in_array($order->status, ['delivered'], true)) {
            $this->fulfillment->deliver($order); // تنفيذ التسليم
          }
        });
      }
    }

    if ($type === 'payment_intent.payment_failed') {
      $pi = $event->data->object ?? null;
      $piId = $pi->id ?? null;

      if ($piId) {
        DB::transaction(function () use ($piId, $eventId) {
          $order = Order::query()
            ->where('stripe_payment_intent_id', $piId)
            ->lockForUpdate()
            ->first();

          if (!$order) return;

          // idempotency
          if ($order->stripe_latest_event_id === $eventId) return;
          $order->stripe_latest_event_id = $eventId;

          // Mark failed only if not already paid/delivered
          if (!in_array($order->status, ['paid', 'delivered'], true)) {
            $order->status = 'failed';
          }

          if (!in_array($order->payment_status, ['paid'], true)) {
            $order->payment_status = 'failed';
          }

          if (empty($order->payment_provider)) {
            $order->payment_provider = 'stripe';
          }

          $order->save();
        });
      }
    }

    return response()->json(['ok' => true]);
  }
}
