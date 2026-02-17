<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DigitalPin;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Services\DigitalPinInventoryService;
use App\Support\DigitalPinNormalizer;
use Illuminate\Http\Request;

class AdminDigitalPinController extends Controller
{
  public function index(Request $r)
  {
    $limit = (int) $r->query('limit', 50);
    $limit = $limit > 0 ? min($limit, 100) : 50;

    $q = DigitalPin::query()->with(['product:id,name_ar', 'package:id,value_label']);

    if ($r->filled('product_id')) {
      $q->where('product_id', (int) $r->query('product_id'));
    }

    if ($r->filled('package_id')) {
      $q->where('package_id', (int) $r->query('package_id'));
    }

    if ($r->filled('status')) {
      $q->where('status', (string) $r->query('status'));
    }

    if ($r->filled('inventory_key')) {
      $q->where('inventory_key', (string) $r->query('inventory_key'));
    }

    return $q->orderByDesc('id')->paginate($limit);
  }

  public function stock(Request $r, DigitalPinInventoryService $inventory)
  {
    $data = $r->validate([
      'product_id' => ['required','integer','exists:products,id'],
      'package_id' => ['required','integer','exists:product_packages,id'],
    ]);

    $package = ProductPackage::query()
      ->with('productPrice')
      ->where('id', (int) $data['package_id'])
      ->firstOrFail();

    $productId = (int) $data['product_id'];

    if ((int) $package->productPrice->product_id !== $productId) {
      return response()->json([
        'message' => 'package_id does not belong to product_id',
      ], 422);
    }

    $inventoryKey = (string) $package->value_label;

    return response()->json([
      'product_id' => $productId,
      'package_id' => (int) $package->id,
      'inventory_key' => $inventoryKey,
      'counts' => $inventory->stockCounts($productId, $inventoryKey),
    ]);
  }

  public function bulkStore(Request $r, DigitalPinInventoryService $inventory)
  {
    $data = $r->validate([
      'product_id' => ['required','integer','exists:products,id'],
      'package_id' => ['required','integer','exists:product_packages,id'],

      // Option A: codes array
      'codes' => ['nullable','array','max:5000'],
      'codes.*' => ['string','max:500'],

      // Option B: textarea
      'codes_text' => ['nullable','string','max:200000'],

      'metadata' => ['nullable','array'],
    ]);

    $product = Product::query()->findOrFail((int) $data['product_id']);

    // Must be inventory-capable product
    $hasInventorySlot = $product->providerSlots()
      ->whereHas('integration', function ($q) {
        $q->where('template_code', 'inventory');
      })->exists();

    if ((string) ($product->fulfillment_type ?? 'api') !== 'digital_pins' && !$hasInventorySlot) {
      return response()->json([
        'message' => 'Product is not inventory-capable (set fulfillment_type=digital_pins OR add inventory provider slot)',
      ], 422);
    }

    $package = ProductPackage::query()
      ->with('productPrice')
      ->where('id', (int) $data['package_id'])
      ->firstOrFail();

    if ((int) $package->productPrice->product_id !== (int) $product->id) {
      return response()->json([
        'message' => 'package_id does not belong to product_id',
      ], 422);
    }

    $codes = DigitalPinNormalizer::parseCodes($data['codes'] ?? null, $data['codes_text'] ?? null);

    if (count($codes) === 0) {
      return response()->json([
        'message' => 'No codes provided',
      ], 422);
    }

    $inventoryKey = (string) $package->value_label;
    $createdBy = auth('api')->id();

    $result = $inventory->ingest(
      (int) $product->id,
      $inventoryKey,
      $codes,
      (int) $package->id,
      $createdBy ? (int) $createdBy : null,
      $data['metadata'] ?? []
    );

    return response()->json([
      'product_id' => (int) $product->id,
      'package_id' => (int) $package->id,
      'inventory_key' => $inventoryKey,
      'total_received' => (int) ($result['total_received'] ?? count($codes)),
      'inserted' => (int) ($result['inserted'] ?? 0),
      'duplicates_in_request' => (array) ($result['duplicates'] ?? []),
      'duplicates_existing' => (array) ($result['existing'] ?? []),
    ], 201);
  }
}
