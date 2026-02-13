<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('categories', function (Blueprint $table) {
      $table->string('requirement_key')->nullable()->after('sort_order');
      // أمثلة: uid | player_id | email | phone
      $table->index('requirement_key');
    });
  }

  public function down(): void
  {
    Schema::table('categories', function (Blueprint $table) {
      $table->dropIndex(['requirement_key']);
      $table->dropColumn('requirement_key');
    });
  }
};
