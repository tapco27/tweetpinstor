<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPackage;
use Illuminate\Http\Request;

class AdminUsdPricingController extends Controller
{
    public function updateProduct(Request $r, $id)
    {
        $product = Product::query()->findOrFail((int) $id);

        $data = $r->validate([
            'currency_mode' => ['required', 'in:TRY,USD'],
            'cost_unit_usd' => ['nullable', 'numeric', 'min:0'],
            'suggested_unit_usd' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->currency_mode = $data['currency_mode'];
        $product->cost_unit_usd = $data['cost_unit_usd'] ?? null;
        $product->suggested_unit_usd = $data['suggested_unit_usd'] ?? null;
        $product->save();

        return response()->json([
            'data' => ['product' => $product->fresh()],
        ]);
    }

    public function updatePackage(Request $r, $id)
    {
        $package = ProductPackage::query()->with('productPrice')->findOrFail((int) $id);

        $data = $r->validate([
            'cost_usd' => ['nullable', 'numeric', 'min:0'],
            'suggested_usd' => ['nullable', 'numeric', 'min:0'],
        ]);

        $package->cost_usd = $data['cost_usd'] ?? null;
        $package->suggested_usd = $data['suggested_usd'] ?? null;
        $package->save();

        // Propagate to all cloned packages across price groups/currencies for the same product.
        // (We don't have a separate "package variant" table yet, so we match by value_label when possible.)
        $productId = (int) ($package->productPrice?->product_id ?? 0);
        $fingerprintValue = trim((string) ($package->value_label ?? ''));

        if ($productId > 0) {
            $q = ProductPackage::query()
                ->whereHas('productPrice', fn ($x) => $x->where('product_id', $productId));

            if ($fingerprintValue !== '') {
                $q->where('value_label', $fingerprintValue);
            } else {
                // fallback match
                $q->where('sort_order', (int) $package->sort_order)
                  ->where('name_ar', (string) $package->name_ar);
            }

            $q->update([
                'cost_usd' => $package->cost_usd,
                'suggested_usd' => $package->suggested_usd,
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => ['package' => $package->fresh()],
        ]);
    }
}
