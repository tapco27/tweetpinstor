<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPackage extends Model
{
  protected $fillable = [
    'product_price_id','name_ar','name_tr','name_en','value_label',
    'price_minor','is_popular','is_active','sort_order'
  ];

  public function productPrice(){ return $this->belongsTo(ProductPrice::class); }
}
