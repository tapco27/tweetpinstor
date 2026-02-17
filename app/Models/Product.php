<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
  protected $guarded = [];

  protected $casts = [
    'is_active' => 'boolean',
    'is_featured' => 'boolean',
    'fulfillment_config' => 'array',
  ];

  public function category(){ return $this->belongsTo(Category::class); }
  public function prices(){ return $this->hasMany(ProductPrice::class); }

  /**
   * Eligible provider integrations for this product.
   *
   * UI use-case: Admin product catalog shows eligible providers list.
   */
  public function eligibleIntegrations()
  {
    return $this->belongsToMany(
      ProviderIntegration::class,
      'product_provider_eligibles',
      'product_id',
      'provider_integration_id'
    )->withTimestamps();
  }

  /**
   * Provider slots (slot=1 primary, slot=2 fallback)
   */
  public function providerSlots()
  {
    return $this->hasMany(ProductProviderSlot::class);
  }

  public function providerSlot(int $slot)
  {
    return $this->hasOne(ProductProviderSlot::class)->where('slot', (int) $slot);
  }

  public function priceFor(?string $currency = null)
  {
    $currency = strtoupper(
      $currency ?? (app()->bound('user_currency') ? app('user_currency') : 'TRY')
    );

    $priceGroupId = 1;
    if (app()->bound('price_group_id')) {
      $priceGroupId = (int) app('price_group_id');
    }
    if ($priceGroupId <= 0) {
      $priceGroupId = 1;
    }

    return $this->hasOne(ProductPrice::class, 'product_id')
      ->where('currency', $currency)
      ->where('price_group_id', $priceGroupId)
      ->where('is_active', true);
  }
}
