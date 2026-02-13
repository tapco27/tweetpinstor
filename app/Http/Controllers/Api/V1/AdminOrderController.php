<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\FulfillmentService;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    public function __construct(private FulfillmentService $fulfillment) {}

    public function markPaid($id)
    {
        return DB::transaction(function () use ($id) {

            $order = Order::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === 'delivered' && $order->payment_status === 'paid') {
                return response()->json([
                    'message' => 'Already delivered',
                    'order' => $order->fresh(['items', 'delivery']),
                ]);
            }

            $order->payment_provider = $order->payment_provider ?: 'manual';
            $order->payment_status = 'paid';
            $order->status = 'paid';
            $order->save();

            $delivery = $this->fulfillment->deliver($order);

            $message = $delivery->status === 'delivered'
                ? 'Marked as paid and delivered'
                : 'Marked as paid; delivery failed';

            return response()->json([
                'message' => $message,
                'order' => $order->fresh(['items', 'delivery']),
                'delivery' => $delivery,
            ]);
        });
    }

    public function retryDelivery($id)
    {
        return DB::transaction(function () use ($id) {

            $order = Order::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->with(['items', 'delivery'])
                ->firstOrFail();

            if ($order->payment_status !== 'paid') {
                return response()->json(['message' => 'Order not paid'], 422);
            }

            $delivery = $this->fulfillment->deliver($order);

            return response()->json([
                'message' => $delivery->status === 'delivered'
                    ? 'Delivery retried: delivered'
                    : 'Delivery retried: failed',
                'order' => $order->fresh(['items', 'delivery']),
                'delivery' => $delivery,
            ]);
        });
    }

    public function deliveryFailed()
    {
        return Order::query()
            ->where('payment_status', 'paid')
            ->whereHas('delivery', fn($q) => $q->where('status', 'failed'))
            ->with(['items', 'delivery'])
            ->orderByDesc('id')
            ->paginate(50);
    }
}
