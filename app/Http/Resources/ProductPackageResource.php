<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductPackageResource extends JsonResource
{
    private function minorToFloat(int $amount, int $minor): float
    {
        if ($minor <= 0) return (float) $amount;
        return round($amount / (10 ** $minor), $minor);
    }

    public function toArray($request)
    {
        $minor = (int) ($this->productPrice->minor_unit ?? 0);
        $amountMinor = (int) ($this->price_minor ?? 0);

        $nameAr = (string) ($this->name_ar ?? '');
        $nameTr = (string) ($this->name_tr ?? '');

        return [
            'id' => (string) $this->id,
            'name' => $nameAr !== '' ? $nameAr : ($nameTr !== '' ? $nameTr : ''),
            'nameAr' => $nameAr,
            'nameTr' => $nameTr,
            'price' => $this->minorToFloat($amountMinor, $minor),
            'value' => (string) ($this->value_label ?? ''),
            'isPopular' => (bool) ($this->is_popular ?? false),

            // extra مفيدة (لن تكسر Flutter)
            'priceMinor' => $amountMinor,
            'currency' => (string) ($this->productPrice->currency ?? ''),
            'minorUnit' => $minor,
            'sortOrder' => (int) ($this->sort_order ?? 0),
        ];
    }
}
