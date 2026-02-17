<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DigitalPinsFulfillmentService
{
  public function __construct(
    private DigitalPinInventoryService $inventory,
  ) {}

  public function deliver(Order $order, Product $product, Delivery $delivery): Delivery
  {
    // Preconditions checked at FulfillmentService level:
    // - delivery exists
    // - order is paid

    return DB::transaction(function () use ($order, $product, $delivery) {

      // Lock delivery + order for idempotency under concurrency.
      $deliveryLocked = Delivery::query()
        ->where('id', (int) $delivery->id)
        ->lockForUpdate()
        ->first();

      $orderLocked = Order::query()
        ->where('id', (int) $order->id)
        ->lockForUpdate()
        ->first();

      if (!$deliveryLocked || !$orderLocked) {
        return $delivery;
      }

      if ((string) $deliveryLocked->status === 'delivered') {
        return $deliveryLocked;
      }

      // We assume single-item orders in this project version.
      $item = $orderLocked->items()->with(['package.productPrice'])->first();
      if (!$item) {
        $deliveryLocked->status = 'failed';
        $deliveryLocked->payload = ['error' => 'No order items'];
        $deliveryLocked->save();
        return $deliveryLocked;
      }

      if (!$item->package) {
        $deliveryLocked->status = 'failed';
        $deliveryLocked->payload = ['error' => 'package_id is required for digital pins fulfillment'];
        $deliveryLocked->save();
        return $deliveryLocked;
      }

      $inventoryKey = (string) $item->package->value_label;
      $qty = (int) ($item->quantity ?? 1);
      $qty = max(1, $qty);

      try {
        $pins = $this->inventory->allocateForOrder(
          (int) $product->id,
          $inventoryKey,
          $qty,
          (int) $orderLocked->id
        );

        $codes = [];
        foreach ($pins as $pin) {
          try {
            $codes[] = [
              'code' => $pin->decryptCode(),
            ];
          } catch (\Throwable $e) {
            // Should never happen; keep safe.
            $codes[] = [
              'code' => null,
            ];
          }
        }

        $deliveryLocked->status = 'delivered';
        $deliveryLocked->payload = [
          'type' => 'digital_pins',
          'product_id' => (int) $product->id,
          'package_id' => (int) $item->package_id,
          'inventory_key' => $inventoryKey,
          'quantity' => $qty,
          'pins' => $codes,
        ];
        $deliveryLocked->delivered_at = now();
        $deliveryLocked->save();

        $orderLocked->status = 'delivered';
        $orderLocked->save();

        return $deliveryLocked;

      } catch (\Throwable $e) {
        Log::warning('digital_pins.fulfillment_failed', [
          'order_id' => (int) $orderLocked->id,
          'product_id' => (int) $product->id,
          'inventory_key' => $inventoryKey,
          'error' => $e->getMessage(),
        ]);

        $deliveryLocked->status = 'failed';
        $deliveryLocked->payload = [
          'type' => 'digital_pins',
          'error' => $e->getMessage(),
          'product_id' => (int) $product->id,
          'package_id' => (int) $item->package_id,
          'inventory_key' => $inventoryKey,
          'requested_quantity' => $qty,
        ];
        $deliveryLocked->save();

        // Keep order as paid (paid but not delivered)
        $orderLocked->status = 'paid';
        $orderLocked->save();

        return $deliveryLocked;
      }
    });
  }
}
