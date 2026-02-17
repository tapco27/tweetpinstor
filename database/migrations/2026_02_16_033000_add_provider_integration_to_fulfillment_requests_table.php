<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('fulfillment_requests', function (Blueprint $table) {
      $table->foreignId('provider_integration_id')
        ->nullable()
        ->after('provider')
        ->constrained('provider_integrations')
        ->nullOnDelete();

      $table->unsignedSmallInteger('slot')->nullable()->after('provider_integration_id');

      $table->index(['provider_integration_id', 'slot']);
    });
  }

  public function down(): void
  {
    Schema::table('fulfillment_requests', function (Blueprint $table) {
      $table->dropIndex(['provider_integration_id', 'slot']);
      $table->dropConstrainedForeignId('provider_integration_id');
      $table->dropColumn('slot');
    });
  }
};
