<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('users', function (Blueprint $table) {
      $table->char('currency', 3)->after('email');
      $table->timestamp('currency_selected_at')->nullable()->after('currency');
      $table->index('currency');
    });
  }

  public function down(): void
  {
    Schema::table('users', function (Blueprint $table) {
      $table->dropIndex(['currency']);
      $table->dropColumn(['currency','currency_selected_at']);
    });
  }
};
