<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => (string) ($this->code ?? ''),
            'name' => (string) ($this->name ?? ''),
            'type' => (string) ($this->type ?? ''),       // manual | gateway
            'scope' => (string) ($this->scope ?? ''),     // topup | order | both
            'currency' => (string) ($this->currency ?? ''), // TRY | SYP | ''
            'instructions' => (string) ($this->instructions ?? ''),
            'isActive' => (bool) ($this->is_active ?? false),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
