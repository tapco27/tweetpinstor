<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductProviderSlot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'slot' => 'integer',
        'is_active' => 'boolean',
        'override_config' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function integration()
    {
        return $this->belongsTo(ProviderIntegration::class, 'provider_integration_id');
    }
}
