<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => (string) $this->id,
            'nameAr' => (string) ($this->name_ar ?? ''),
            'nameTr' => (string) ($this->name_tr ?? ''),
            'nameEn' => (string) ($this->name_en ?? ''),
            'requirementKey' => $this->requirement_key ? (string) $this->requirement_key : '',
            'sortOrder' => (int) ($this->sort_order ?? 0),
            'isActive' => (bool) ($this->is_active ?? false),
        ];
    }
}
