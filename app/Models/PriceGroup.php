<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceGroup extends Model
{
    public const DEFAULT_ID = 1;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];
}
