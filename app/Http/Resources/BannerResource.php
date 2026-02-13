<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => (string) $this->id,
            'imageUrl' => (string) ($this->image_url ?? ''),
            'linkType' => (string) ($this->link_type ?? ''),
            'linkValue' => (string) ($this->link_value ?? ''),
            'currency' => (string) ($this->currency ?? ''),
            'sortOrder' => (int) ($this->sort_order ?? 0),
            'isActive' => (bool) ($this->is_active ?? false),
        ];
    }
}
