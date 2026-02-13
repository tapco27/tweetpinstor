<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::table('products', function (Blueprint $table) {
      $table->string('fulfillment_type')->default('api')->after('product_type');
      $table->string('provider_code')->nullable()->after('fulfillment_type'); // مفتاح المزود/الخدمة
      $table->jsonb('fulfillment_config')->nullable()->after('provider_code'); // endpoints/headers mapping
      $table->index(['fulfillment_type','provider_code']);
    });
  }

  public function down(): void
  {
    Schema::table('products', function (Blueprint $table) {
      $table->dropIndex(['fulfillment_type','provider_code']);
      $table->dropColumn(['fulfillment_type','provider_code','fulfillment_config']);
    });
  }
};
