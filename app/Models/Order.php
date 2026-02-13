<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
  protected $fillable = [
    'user_id',
    'currency',
    'status',

    'subtotal_amount_minor',
    'fees_amount_minor',
    'total_amount_minor',

    'payment_status',
    'payment_provider',

    'stripe_payment_intent_id',
    'stripe_latest_event_id',
  ];

  protected $casts = [
    'subtotal_amount_minor' => 'integer',
    'fees_amount_minor' => 'integer',
    'total_amount_minor' => 'integer',
  ];

  public function user(){ return $this->belongsTo(User::class); }
  public function items(){ return $this->hasMany(OrderItem::class); }
  public function delivery(){ return $this->hasOne(Delivery::class); }
}
