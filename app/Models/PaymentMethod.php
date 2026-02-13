<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
    ];
}
