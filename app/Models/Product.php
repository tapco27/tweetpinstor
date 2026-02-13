<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
  protected $fillable = [
    'category_id',
    'product_type',
    'name_ar','name_tr','name_en',
    'description_ar','description_tr','description_en',
    'image_url',
    'is_active','is_featured','sort_order',

    // fulfillment
    'fulfillment_type',
    'provider_code',
    'fulfillment_config',
  ];

  protected $casts = [
    'is_active' => 'boolean',
    'is_featured' => 'boolean',
    'fulfillment_config' => 'array',
  ];

  public function category(){ return $this->belongsTo(Category::class); }
  public function prices(){ return $this->hasMany(ProductPrice::class); }

  public function priceFor(?string $currency = null)
  {
    $currency = strtoupper(
      $currency ?? (app()->bound('user_currency') ? app('user_currency') : 'TRY')
    );

    return $this->hasOne(ProductPrice::class, 'product_id')
      ->where('currency', $currency)
      ->where('is_active', true);
  }
}
