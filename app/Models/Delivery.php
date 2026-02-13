<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
  protected $casts = [
    'payload' => 'array',
    'delivered_at' => 'datetime',
  ];

  protected $fillable = ['order_id','status','payload','delivered_at'];

  public function order(){ return $this->belongsTo(Order::class); }
}
