<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('categories', function (Blueprint $table) {
      $table->id();
      $table->string('name_ar');
      $table->string('name_tr')->nullable();
      $table->string('name_en')->nullable();
      $table->boolean('is_active')->default(true);
      $table->integer('sort_order')->default(0);
      $table->timestamps();

      $table->index(['is_active','sort_order']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('categories');
  }
};
