<?php

namespace App\Services;

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
    $unit = (int)$price->unit_price_minor;
    if ($unit <= 0) throw new \InvalidArgumentException('Invalid unit price');
    if ($price->min_qty !== null && $quantity < $price->min_qty) throw new \InvalidArgumentException('Quantity below min');
    if ($price->max_qty !== null && $quantity > $price->max_qty) throw new \InvalidArgumentException('Quantity above max');

    $total = $unit * $quantity;
    return ['unit_price_minor' => $unit, 'quantity' => $quantity, 'total_price_minor' => $total];
  }
}
