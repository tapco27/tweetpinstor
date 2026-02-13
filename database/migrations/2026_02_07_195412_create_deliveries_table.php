<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('deliveries', function (Blueprint $table) {
      $table->id();
      $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

      $table->string('status')->default('pending'); // pending | delivered | failed
      $table->jsonb('payload')->nullable(); // codes / delivery result
      $table->timestamp('delivered_at')->nullable();

      $table->timestamps();
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('deliveries');
  }
};
