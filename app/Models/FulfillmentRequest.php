<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FulfillmentRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'http_status' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
