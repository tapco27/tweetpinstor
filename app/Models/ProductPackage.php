<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPackage extends Model
{
  protected $guarded = [];

  public function productPrice(){ return $this->belongsTo(ProductPrice::class); }
}
