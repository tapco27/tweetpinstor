<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\FulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeliverOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $orderId) {}

    public function handle(FulfillmentService $fulfillment): void
    {
        $order = Order::query()
            ->with(['items.product', 'items.package', 'delivery'])
            ->find($this->orderId);

        if (!$order) {
            return;
        }

        try {
            $fulfillment->deliver($order);
        } catch (\Throwable $e) {
            Log::error('deliver_order_job.failed', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
