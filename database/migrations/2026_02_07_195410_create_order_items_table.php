<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('order_items', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_id')->constrained()->cascadeOnDelete();

      $table->foreignId('product_id')->constrained()->restrictOnDelete();
      $table->foreignId('product_price_id')->constrained('product_prices')->restrictOnDelete();
      $table->foreignId('package_id')->nullable()->constrained('product_packages')->nullOnDelete();

      $table->unsignedInteger('quantity')->default(1);
      $table->unsignedBigInteger('unit_price_minor');
      $table->unsignedBigInteger('total_price_minor');

      $table->jsonb('metadata')->nullable(); // player_id, username, ...
      $table->timestamps();

      $table->index(['order_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('order_items');
  }
};
