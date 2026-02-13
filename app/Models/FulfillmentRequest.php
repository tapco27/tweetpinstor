<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FulfillmentRequest extends Model
{
    protected $fillable = [
        'order_id',
        'provider',
        'request_id',
        'status',
        'http_status',
        'request_payload',
        'response_payload',
        'error_message',
    ];

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
