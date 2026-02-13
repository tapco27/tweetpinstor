<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverOrderJob;
use App\Models\Order;
use App\Services\StripeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeService $stripe,
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

        // 1) سجل الحدث في webhook_events (provider=stripe) بشكل idempotent
        $now = now();

        try {
            DB::table('webhook_events')->insert([
                'provider' => 'stripe',
                'event_id' => (string) $eventId,
                'created_at' => $now,
                'updated_at' => $now,
                // processed_at تظل null افتراضياً
            ]);
        } catch (QueryException $e) {
            // duplicate (unique violation) => إذا كان processed_at موجود رجع OK فوراً
            // وإذا كان null (محاولة سابقة انقطعت) أكمل المعالجة
            $code = (string) $e->getCode();
            $sqlState = (string) ($e->errorInfo[0] ?? '');

            $isUniqueViolation =
                in_array($code, ['23505', '23000'], true) ||
                in_array($sqlState, ['23505', '23000'], true);

            if (!$isUniqueViolation) {
                throw $e;
            }

            $row = DB::table('webhook_events')
                ->select('processed_at')
                ->where('provider', 'stripe')
                ->where('event_id', (string) $eventId)
                ->first();

            if ($row && !empty($row->processed_at)) {
                return response()->json(['ok' => true]);
            }
            // processed_at فارغ => أكمل (retry بعد فشل سابق)
        }

        // 2) أكمل معالجة الدفع/التسليم ثم حدّث processed_at
        $processed = false;

        try {
            if ($type === 'payment_intent.succeeded') {
                $pi = $event->data->object ?? null;
                $piId = $pi->id ?? null;

                if ($piId) {
                    $orderIdToDeliver = null;

                    DB::transaction(function () use ($piId, $eventId, &$orderIdToDeliver) {
                        $order = Order::query()
                            ->where('stripe_payment_intent_id', $piId)
                            ->lockForUpdate()
                            ->first();

                        if (!$order) return;

                        // idempotency على مستوى الطلب (إضافي)
                        if ($order->stripe_latest_event_id === $eventId) return;

                        $order->stripe_latest_event_id = $eventId;

                        // Mark paid (both status + payment_status)
                        if (!in_array($order->status, ['paid', 'delivered'], true)) {
                            $order->status = 'paid';
                        }

                        if ($order->payment_status !== 'paid') {
                            $order->payment_status = 'paid';
                        }

                        $order->payment_provider = $order->payment_provider ?: 'stripe';
                        $order->save();

                        // فقط خزّن ID — بدون HTTP
                        if ($order->status !== 'delivered') {
                            $orderIdToDeliver = (int) $order->id;
                        }
                    });

                    // بعد الـ transaction: Dispatch Job
                    if ($orderIdToDeliver) {
                        DeliverOrderJob::dispatch($orderIdToDeliver);
                    }
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

                        // idempotency على مستوى الطلب (إضافي)
                        if ($order->stripe_latest_event_id === $eventId) return;

                        $order->stripe_latest_event_id = $eventId;

                        // Mark failed only if not already paid/delivered
                        if (!in_array($order->status, ['paid', 'delivered'], true)) {
                            $order->status = 'failed';
                        }

                        if ($order->payment_status !== 'paid') {
                            $order->payment_status = 'failed';
                        }

                        $order->payment_provider = $order->payment_provider ?: 'stripe';
                        $order->save();
                    });
                }
            }

            $processed = true;
        } finally {
            if ($processed) {
                DB::table('webhook_events')
                    ->where('provider', 'stripe')
                    ->where('event_id', (string) $eventId)
                    ->update([
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
