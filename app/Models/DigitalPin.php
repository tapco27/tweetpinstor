<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class DigitalPin extends Model
{
  protected $guarded = [];

  protected $casts = [
    'metadata' => 'array',
    'sold_at' => 'datetime',
  ];

  protected $hidden = [
    'code_encrypted',
  ];

  public function product(){ return $this->belongsTo(Product::class); }
  public function package(){ return $this->belongsTo(ProductPackage::class, 'package_id'); }
  public function order(){ return $this->belongsTo(Order::class); }

  public function decryptCode(): string
  {
    return Crypt::decryptString((string) $this->code_encrypted);
  }
}
