<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('product_provider_eligibles', function (Blueprint $table) {
      $table->id();

      $table->foreignId('product_id')->constrained()->cascadeOnDelete();
      $table->foreignId('provider_integration_id')->constrained('provider_integrations')->cascadeOnDelete();

      $table->timestamps();

      $table->unique(['product_id','provider_integration_id']);
      $table->index(['provider_integration_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('product_provider_eligibles');
  }
};
