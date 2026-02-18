<?php

namespace App\Services;

use App\Models\FxRate;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductPackage;

class PricingService
{
  public function calculateLine(Product $product, ProductPrice $price, ?ProductPackage $package, int $quantity): array
  {
    if ($product->product_type === 'fixed_package') {
      if (!$package) {
        throw new \InvalidArgumentException('package_id required');
      }
      $unit = (int)$package->price_minor;
      $qty = 1;
      return ['unit_price_minor' => $unit, 'quantity' => $qty, 'total_price_minor' => $unit];
    }

    // flexible_quantity
    if ($price->min_qty !== null && $quantity < $price->min_qty) throw new \InvalidArgumentException('Quantity below min');
    if ($price->max_qty !== null && $quantity > $price->max_qty) throw new \InvalidArgumentException('Quantity above max');

    // ✅ USD-based flexible products: compute total from USD to avoid "unit price rounds to 0" issues.
    if ((string) ($product->currency_mode ?? 'TRY') === 'USD') {
      $usdUnit = $price->unit_price_usd ?? $product->suggested_unit_usd;
      if ($usdUnit !== null) {
        $fx = $this->fxRateOrFail((string) $price->currency);
        $minorUnit = (int) ($price->minor_unit ?? (int) config('money.minor_units.' . strtoupper((string) $price->currency), 2));
        $scale = $minorUnit > 0 ? (10 ** $minorUnit) : 1;

        $unitMinor = (int) round(((float) $usdUnit) * $fx * $scale);
        $totalMinor = (int) round(((float) $usdUnit) * $fx * $scale * $quantity);
        if ($totalMinor < 0) {
          throw new \InvalidArgumentException('Invalid total price');
        }

        return ['unit_price_minor' => $unitMinor, 'quantity' => $quantity, 'total_price_minor' => $totalMinor];
      }
      // If USD mode but USD unit price missing: fall back to stored unit_price_minor (if any)
    }

    $unit = (int)$price->unit_price_minor;
    if ($unit <= 0) throw new \InvalidArgumentException('Invalid unit price');

    $total = $unit * $quantity;
    return ['unit_price_minor' => $unit, 'quantity' => $quantity, 'total_price_minor' => $total];
  }

  private function fxRateOrFail(string $quoteCurrency): float
  {
    $quoteCurrency = strtoupper($quoteCurrency);
    $pair = 'USD_' . $quoteCurrency;
    $rate = FxRate::query()->where('pair', $pair)->value('rate');
    if ($rate === null) {
      throw new \InvalidArgumentException('FX rate not set: ' . $pair);
    }
    return (float) $rate;
  }
}
