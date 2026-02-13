<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('product_packages', function (Blueprint $table) {
      $table->id();
      $table->foreignId('product_price_id')->constrained('product_prices')->cascadeOnDelete();

      $table->string('name_ar');
      $table->string('name_tr')->nullable();
      $table->string('name_en')->nullable();

      $table->string('value_label'); // مثل: 50,000 CRYSTAL
      $table->unsignedBigInteger('price_minor'); // integer

      $table->boolean('is_popular')->default(false);
      $table->boolean('is_active')->default(true);
      $table->integer('sort_order')->default(0);

      $table->timestamps();

      $table->index(['product_price_id','is_active','sort_order']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('product_packages');
  }
};
