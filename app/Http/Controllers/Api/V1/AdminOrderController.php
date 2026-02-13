<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverOrderJob;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    public function markPaid($id)
    {
        $orderIdToDeliver = null;
        $alreadyDeliveredAndPaid = false;

        DB::transaction(function () use ($id, &$orderIdToDeliver, &$alreadyDeliveredAndPaid) {

            $order = Order::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === 'delivered' && $order->payment_status === 'paid') {
                $alreadyDeliveredAndPaid = true;
                return;
            }

            $order->payment_provider = $order->payment_provider ?: 'manual';

            if ($order->payment_status !== 'paid') {
                $order->payment_status = 'paid';
            }

            if (!in_array($order->status, ['paid', 'delivered'], true)) {
                $order->status = 'paid';
            }

            $order->save();

            // فقط خزّن ID — بدون HTTP
            if ($order->status !== 'delivered') {
                $orderIdToDeliver = (int) $order->id;
            }
        });

        if ($alreadyDeliveredAndPaid) {
            $order = Order::query()->with(['items', 'delivery'])->findOrFail($id);

            return response()->json([
                'message' => 'Already delivered',
                'order' => $order,
            ]);
        }

        // بعد الـ transaction: Dispatch Job
        if ($orderIdToDeliver) {
            DeliverOrderJob::dispatch($orderIdToDeliver);
        }

        $order = Order::query()->with(['items', 'delivery'])->findOrFail($id);

        return response()->json([
            'message' => $orderIdToDeliver ? 'Marked as paid; delivery queued' : 'Marked as paid',
            'order' => $order,
        ]);
    }

    public function retryDelivery($id)
    {
        $orderIdToDeliver = null;
        $notPaid = false;
        $alreadyDelivered = false;

        DB::transaction(function () use ($id, &$orderIdToDeliver, &$notPaid, &$alreadyDelivered) {

            $order = Order::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->payment_status !== 'paid') {
                $notPaid = true;
                return;
            }

            if ($order->status === 'delivered') {
                $alreadyDelivered = true;
                return;
            }

            // تأكيد الحالة فقط (بدون HTTP)
            if (!in_array($order->status, ['paid', 'delivered'], true)) {
                $order->status = 'paid';
                $order->save();
            }

            $orderIdToDeliver = (int) $order->id;
        });

        if ($notPaid) {
            return response()->json(['message' => 'Order not paid'], 422);
        }

        if ($alreadyDelivered) {
            $order = Order::query()->with(['items', 'delivery'])->findOrFail($id);

            return response()->json([
                'message' => 'Already delivered',
                'order' => $order,
            ]);
        }

        // بعد الـ transaction: Dispatch Job
        if ($orderIdToDeliver) {
            DeliverOrderJob::dispatch($orderIdToDeliver);
        }

        $order = Order::query()->with(['items', 'delivery'])->findOrFail($id);

        return response()->json([
            'message' => 'Delivery retried: queued',
            'order' => $order,
        ]);
    }

    public function deliveryFailed()
    {
        return Order::query()
            ->where('payment_status', 'paid')
            ->whereHas('delivery', fn ($q) => $q->where('status', 'failed'))
            ->with(['items', 'delivery'])
            ->orderByDesc('id')
            ->paginate(50);
    }
}
