<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rate' => 'string',
    ];
}
