<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
  protected $guarded = [];

  protected $casts = [
    'is_active' => 'boolean',
  ];

  public function product(){ return $this->belongsTo(Product::class); }
  public function priceGroup(){ return $this->belongsTo(PriceGroup::class, 'price_group_id'); }
  public function packages(){ return $this->hasMany(ProductPackage::class); }
}
