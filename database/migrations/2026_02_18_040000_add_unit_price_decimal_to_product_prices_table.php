<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('product_prices', 'unit_price_decimal')) {
                $table->decimal('unit_price_decimal', 24, 10)->nullable()->after('unit_price_usd');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            if (Schema::hasColumn('product_prices', 'unit_price_decimal')) {
                $table->dropColumn('unit_price_decimal');
            }
        });
    }
};
