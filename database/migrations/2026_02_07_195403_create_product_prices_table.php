<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('product_prices', function (Blueprint $table) {
      $table->id();
      $table->foreignId('product_id')->constrained()->cascadeOnDelete();

      $table->char('currency', 3); // TRY | SYP
      $table->unsignedSmallInteger('minor_unit')->default(2); // TRY=2, SYP=0

      // flexible_quantity:
      $table->unsignedBigInteger('unit_price_minor')->nullable(); // integer
      $table->unsignedInteger('min_qty')->nullable();
      $table->unsignedInteger('max_qty')->nullable();

      $table->boolean('is_active')->default(true);

      $table->timestamps();

      $table->unique(['product_id','currency']);
      $table->index(['currency','is_active']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('product_prices');
  }
};
