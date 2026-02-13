<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray($request)
    {
        $product = null;
        if ($this->resource && method_exists($this->resource, 'relationLoaded') && $this->resource->relationLoaded('product') && $this->product) {
            $product = [
                'id' => (string) $this->product->id,
                'nameAr' => (string) ($this->product->name_ar ?? ''),
                'nameTr' => (string) ($this->product->name_tr ?? ''),
                'nameEn' => (string) ($this->product->name_en ?? ''),
                'imageUrl' => (string) ($this->product->image_url ?? ''),
            ];
        }

        $package = null;
        if ($this->resource && method_exists($this->resource, 'relationLoaded') && $this->resource->relationLoaded('package') && $this->package) {
            $package = [
                'id' => (string) $this->package->id,
                'nameAr' => (string) ($this->package->name_ar ?? ''),
                'nameTr' => (string) ($this->package->name_tr ?? ''),
                'value' => (string) ($this->package->value_label ?? ''),
            ];
        }

        return [
            'id' => (string) $this->id,
            'productId' => (string) $this->product_id,
            'productPriceId' => (string) $this->product_price_id,
            'packageId' => $this->package_id ? (string) $this->package_id : null,
            'quantity' => (int) ($this->quantity ?? 0),
            'unitPriceMinor' => (int) ($this->unit_price_minor ?? 0),
            'totalPriceMinor' => (int) ($this->total_price_minor ?? 0),
            'metadata' => $this->metadata, // لازم metadata cast array في Model

            // optional (يساعد UI)
            'product' => $product,
            'package' => $package,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
