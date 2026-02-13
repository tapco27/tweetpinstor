<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'direction' => (string) ($this->direction ?? ''), // credit | debit
            'status' => (string) ($this->status ?? ''),       // posted | pending | reversed
            'type' => (string) ($this->type ?? ''),           // topup | order_payment | refund | adjustment
            'amountMinor' => (int) ($this->amount_minor ?? 0),
            'balanceAfterMinor' => $this->balance_after_minor !== null ? (int) $this->balance_after_minor : null,
            'referenceType' => (string) ($this->reference_type ?? ''),
            'referenceId' => (int) ($this->reference_id ?? 0),
            'referenceUuid' => (string) ($this->reference_uuid ?? ''),
            'created_at' => $this->created_at,
        ];
    }
}
