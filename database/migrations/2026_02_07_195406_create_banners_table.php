<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('banners', function (Blueprint $table) {
      $table->id();
      $table->string('image_url');

      $table->string('link_type')->nullable();  // product | category | external
      $table->string('link_value')->nullable(); // id or url
      $table->boolean('is_active')->default(true);
      $table->integer('sort_order')->default(0);

      // اختياري: بنرات حسب العملة (حتى ما يطلع رابط منتج بعملة ثانية)
      $table->char('currency', 3)->nullable(); // TRY | SYP | null=عام

      $table->timestamps();

      $table->index(['is_active','sort_order']);
      $table->index(['currency','is_active']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('banners');
  }
};
