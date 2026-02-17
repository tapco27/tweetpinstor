<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('product_provider_slots', function (Blueprint $table) {
      $table->id();

      $table->foreignId('product_id')->constrained()->cascadeOnDelete();

      // 1 = Primary, 2 = Fallback
      $table->unsignedSmallInteger('slot');

      $table->foreignId('provider_integration_id')
        ->nullable()
        ->constrained('provider_integrations')
        ->nullOnDelete();

      // Optional per-slot overrides (e.g. fulfillment path)
      $table->jsonb('override_config')->nullable();

      $table->boolean('is_active')->default(true);

      $table->timestamps();

      $table->unique(['product_id','slot']);
      $table->index(['slot','is_active']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('product_provider_slots');
  }
};
