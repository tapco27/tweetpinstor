<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WalletTopupResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'topupUuid' => (string) ($this->topup_uuid ?? ''),
            'currency' => (string) ($this->currency ?? ''),
            'amountMinor' => (int) ($this->amount_minor ?? 0),
            'status' => (string) ($this->status ?? ''),

            'payerFullName' => (string) ($this->payer_full_name ?? ''),
            'nationalId' => (string) ($this->national_id ?? ''),
            'phone' => (string) ($this->phone ?? ''),
            'receiptNote' => (string) ($this->receipt_note ?? ''),

            'paymentMethod' => $this->relationLoaded('paymentMethod') && $this->paymentMethod
                ? (new PaymentMethodResource($this->paymentMethod))->resolve($request)
                : null,

            'reviewedAt' => $this->reviewed_at,
            'reviewNote' => (string) ($this->review_note ?? ''),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
