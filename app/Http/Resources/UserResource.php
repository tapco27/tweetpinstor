<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => (string) $this->id,
            'name' => (string) ($this->name ?? ''),
            'email' => (string) ($this->email ?? ''),
            'currency' => (string) ($this->currency ?? ''),
            'currencySelectedAt' => $this->currency_selected_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
