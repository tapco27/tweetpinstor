<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    private function minorToFloat(int $amount, int $minor): float
    {
        if ($minor <= 0) return (float) $amount;
        return round($amount / (10 ** $minor), $minor);
    }

    public function toArray($request)
    {
        $pf = $this->priceFor ?? null;

        $minor = (int) ($pf->minor_unit ?? 0);
        $currency = (string) ($pf->currency ?? '');

        $nameAr = (string) ($this->name_ar ?? '');
        $nameTr = (string) ($this->name_tr ?? '');
        $descAr = (string) ($this->description_ar ?? '');
        $descTr = (string) ($this->description_tr ?? '');

        $packages = [];
        if ($pf && $this->product_type === 'fixed_package') {
            $packages = ProductPackageResource::collection(
                $pf->packages->loadMissing('productPrice')
            );
        }

        // price:
        // - flexible_quantity: unit price
        // - fixed_package: أقل باقة (للعرض بالقوائم)
        $price = 0.0;
        if ($pf) {
            if ($this->product_type === 'flexible_quantity') {
                $price = $this->minorToFloat((int) ($pf->unit_price_minor ?? 0), $minor);
            } else {
                $minPkg = (int) ($pf->packages?->min('price_minor') ?? 0);
                $price = $this->minorToFloat($minPkg, $minor);
            }
        }

        $productTypeConfig = null;
        if ($pf && $this->product_type === 'flexible_quantity') {
            $productTypeConfig = [
                'minQty' => $pf->min_qty !== null ? (int) $pf->min_qty : null,
                'maxQty' => $pf->max_qty !== null ? (int) $pf->max_qty : null,
                'unitPriceMinor' => $pf->unit_price_minor !== null ? (int) $pf->unit_price_minor : null,
                'unitPrice' => $this->minorToFloat((int) ($pf->unit_price_minor ?? 0), $minor),
                'minorUnit' => $minor,
            ];
        }

        return [
            'id' => (string) $this->id,
            'name' => $nameAr !== '' ? $nameAr : ($nameTr !== '' ? $nameTr : ''),
            'nameAr' => $nameAr,
            'nameTr' => $nameTr,
            'description' => $descAr !== '' ? $descAr : $descTr,
            'descriptionAr' => $descAr,
            'descriptionTr' => $descTr,
            'price' => (float) $price,
            'currency' => $currency,
            'category' => $this->category_id ? (string) $this->category_id : '',
            'imageUrl' => (string) ($this->image_url ?? ''),
            'isActive' => (bool) ($this->is_active ?? false),
            'packages' => $packages instanceof JsonResource ? $packages->resolve($request) : $packages,
            'productType' => [
                'type' => (string) ($this->product_type ?? ''),
                'config' => $productTypeConfig,
            ],

            // Optional extra
            'isFeatured' => (bool) ($this->is_featured ?? false),

            // لازم يكونوا snake_case لأن Flutter model يستخدم JsonKey(name: 'created_at')
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
