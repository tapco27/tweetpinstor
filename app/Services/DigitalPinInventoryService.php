<?php

namespace App\Services;

use App\Models\DigitalPin;
use App\Support\DigitalPinNormalizer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DigitalPinInventoryService
{
  /**
   * Ingest a batch of digital pin codes.
   *
   * - Filters duplicates (existing in DB OR repeated in same request)
   * - Inserts only new ones
   * - Returns inserted + duplicates lists
   */
  public function ingest(
    int $productId,
    string $inventoryKey,
    array $codes,
    ?int $packageId = null,
    ?int $createdBy = null,
    array $metadata = []
  ): array {

    // 1) Build hashes
    $rows = [];
    $hashToCode = [];
    $duplicatesInRequest = [];

    foreach ($codes as $code) {
      if (!is_string($code)) continue;
      $storeCode = DigitalPinNormalizer::normalizeForStore($code);
      if ($storeCode === '') continue;

      $hash = DigitalPinNormalizer::hash($storeCode);

      if (isset($hashToCode[$hash])) {
        // duplicated inside the same request
        $duplicatesInRequest[] = $storeCode;
        continue;
      }

      $hashToCode[$hash] = $storeCode;

      $rows[$hash] = [
        'product_id' => $productId,
        'package_id' => $packageId,
        'inventory_key' => $inventoryKey,
        'code_encrypted' => Crypt::encryptString($storeCode),
        'code_hash' => $hash,
        'status' => 'available',
        'order_id' => null,
        'sold_at' => null,
        'metadata' => !empty($metadata) ? json_encode($metadata) : null,
        'created_by' => $createdBy,
        'created_at' => now(),
        'updated_at' => now(),
      ];
    }

    if (count($rows) === 0) {
      return [
        'inserted' => 0,
        'duplicates' => array_values(array_unique($duplicatesInRequest)),
        'existing' => [],
        'total_received' => count($codes),
      ];
    }

    $hashes = array_keys($rows);

    // 2) Detect duplicates already in DB
    $existingHashes = DigitalPin::query()
      ->whereIn('code_hash', $hashes)
      ->pluck('code_hash')
      ->all();

    $existingCodes = [];
    foreach ($existingHashes as $h) {
      if (isset($hashToCode[$h])) {
        $existingCodes[] = $hashToCode[$h];
        unset($rows[$h]);
      }
    }

    // 3) Insert remaining
    $inserted = 0;
    DB::transaction(function () use (&$inserted, $rows) {
      // Chunk insert for safety
      $chunk = [];
      foreach ($rows as $row) {
        $chunk[] = $row;
        if (count($chunk) >= 1000) {
          DigitalPin::query()->insert($chunk);
          $inserted += count($chunk);
          $chunk = [];
        }
      }

      if (count($chunk) > 0) {
        DigitalPin::query()->insert($chunk);
        $inserted += count($chunk);
      }
    });

    return [
      'inserted' => $inserted,
      'duplicates' => array_values(array_unique($duplicatesInRequest)),
      'existing' => array_values(array_unique($existingCodes)),
      'total_received' => count($codes),
    ];
  }

  public function stockCounts(int $productId, string $inventoryKey): array
  {
    $rows = DigitalPin::query()
      ->selectRaw('status, count(*) as c')
      ->where('product_id', $productId)
      ->where('inventory_key', $inventoryKey)
      ->groupBy('status')
      ->get();

    $out = [
      'available' => 0,
      'sold' => 0,
    ];

    foreach ($rows as $r) {
      $status = (string) $r->status;
      $out[$status] = (int) $r->c;
    }

    return $out;
  }

  /**
   * Allocate N pins for an order (transactional, concurrency-safe).
   * Returns array of DigitalPin models.
   */
  public function allocateForOrder(
    int $productId,
    string $inventoryKey,
    int $count,
    int $orderId
  ): array {

    $count = max(1, (int) $count);

    return DB::transaction(function () use ($productId, $inventoryKey, $count, $orderId) {

      // Lock available rows and skip already locked ones (PG: FOR UPDATE SKIP LOCKED)
      $pins = DigitalPin::query()
        ->where('product_id', $productId)
        ->where('inventory_key', $inventoryKey)
        ->where('status', 'available')
        ->orderBy('id')
        ->lockForUpdate()
        ->skipLocked()
        ->limit($count)
        ->get();

      if ($pins->count() < $count) {
        throw new \RuntimeException('Not enough digital pins in stock');
      }

      $ids = $pins->pluck('id')->all();

      DigitalPin::query()
        ->whereIn('id', $ids)
        ->update([
          'status' => 'sold',
          'order_id' => $orderId,
          'sold_at' => now(),
          'updated_at' => now(),
        ]);

      // Refresh models (we still need encrypted codes)
      return DigitalPin::query()->whereIn('id', $ids)->get()->all();
    });
  }
}
