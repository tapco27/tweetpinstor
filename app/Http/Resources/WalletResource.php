<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray($request): array
    {
        $currency = (string) ($this->currency ?? '');
        $minor = (int) (config('money.minor_units.' . $currency, 2));

        return [
            'id' => (string) $this->id,
            'currency' => $currency,
            'balanceMinor' => (int) ($this->balance_minor ?? 0),
            'minorUnit' => $minor,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
