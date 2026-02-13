<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
  protected $casts = [
    'metadata' => 'array',
    'quantity' => 'integer',
    'unit_price_minor' => 'integer',
    'total_price_minor' => 'integer',
  ];
  

  protected $fillable = [
    'order_id','product_id','product_price_id','package_id',
    'quantity','unit_price_minor','total_price_minor','metadata'
  ];

  public function order(){ return $this->belongsTo(Order::class); }
  public function product(){ return $this->belongsTo(Product::class); }
  public function productPrice(){ return $this->belongsTo(ProductPrice::class); }
  public function package(){ return $this->belongsTo(ProductPackage::class, 'package_id'); }
}
