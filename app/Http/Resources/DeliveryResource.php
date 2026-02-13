<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => (string) $this->id,
            'status' => (string) ($this->status ?? ''),
            'payload' => $this->payload, // لازم payload cast array في Model
            'deliveredAt' => $this->delivered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
