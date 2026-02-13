<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
  protected $fillable = [
    'product_id','currency','minor_unit','unit_price_minor','min_qty','max_qty','is_active'
  ];

  public function product(){ return $this->belongsTo(Product::class); }
  public function packages(){ return $this->hasMany(ProductPackage::class); }
}
