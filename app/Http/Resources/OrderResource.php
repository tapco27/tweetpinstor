<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        $items = [];
        if ($this->resource && method_exists($this->resource, 'relationLoaded') && $this->resource->relationLoaded('items')) {
            $items = OrderItemResource::collection($this->items)->resolve($request);
        }

        $delivery = null;
        if ($this->resource && method_exists($this->resource, 'relationLoaded') && $this->resource->relationLoaded('delivery') && $this->delivery) {
            $delivery = (new DeliveryResource($this->delivery))->resolve($request);
        }

        return [
            'id' => (string) $this->id,
            'status' => (string) ($this->status ?? ''),
            'paymentStatus' => (string) ($this->payment_status ?? ''),
            'paymentProvider' => (string) ($this->payment_provider ?? ''),
            'currency' => (string) ($this->currency ?? ''),

            'subtotalAmountMinor' => (int) ($this->subtotal_amount_minor ?? 0),
            'feesAmountMinor' => (int) ($this->fees_amount_minor ?? 0),
            'totalAmountMinor' => (int) ($this->total_amount_minor ?? 0),

            'stripePaymentIntentId' => $this->stripe_payment_intent_id ? (string) $this->stripe_payment_intent_id : '',

            'items' => $items,
            'delivery' => $delivery,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
