<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request)
    {
        $required = method_exists($this->resource, 'effectiveRequirements')
            ? $this->resource->effectiveRequirements()
            : (is_array($this->requirements ?? null) ? $this->requirements : []);

        return [
            'id' => (string) $this->id,
            'nameAr' => (string) ($this->name_ar ?? ''),
            'nameTr' => (string) ($this->name_tr ?? ''),
            'nameEn' => (string) ($this->name_en ?? ''),
            // New (preferred)
            'requiredFields' => $required,
            'purchaseMode' => (string) ($this->purchase_mode ?? ''),

            // Backward compatibility (deprecated): first required field
            'requirementKey' => isset($required[0]) ? (string) $required[0] : ($this->requirement_key ? (string) $this->requirement_key : ''),
            'sortOrder' => (int) ($this->sort_order ?? 0),
            'isActive' => (bool) ($this->is_active ?? false),
        ];
    }
}
