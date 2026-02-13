<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('products', function (Blueprint $table) {
      $table->id();
      $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

      $table->string('product_type'); // fixed_package | flexible_quantity
      $table->string('name_ar');
      $table->string('name_tr')->nullable();
      $table->string('name_en')->nullable();

      $table->text('description_ar')->nullable();
      $table->text('description_tr')->nullable();
      $table->text('description_en')->nullable();

      $table->string('image_url')->nullable();

      $table->boolean('is_active')->default(true);
      $table->boolean('is_featured')->default(false);
      $table->integer('sort_order')->default(0);

      $table->timestamps();

      $table->index(['is_active','is_featured','sort_order']);
      $table->index(['category_id','is_active']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('products');
  }
};
