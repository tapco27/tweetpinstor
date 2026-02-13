<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
  protected $guarded = [];

  public function product(){ return $this->belongsTo(Product::class); }
  public function packages(){ return $this->hasMany(ProductPackage::class); }
}
