<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'currency_mode')) {
                $table->string('currency_mode', 3)->default('TRY');
            }
            if (!Schema::hasColumn('products', 'cost_unit_usd')) {
                $table->decimal('cost_unit_usd', 24, 10)->nullable();
            }
            if (!Schema::hasColumn('products', 'suggested_unit_usd')) {
                $table->decimal('suggested_unit_usd', 24, 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'suggested_unit_usd')) {
                $table->dropColumn('suggested_unit_usd');
            }
            if (Schema::hasColumn('products', 'cost_unit_usd')) {
                $table->dropColumn('cost_unit_usd');
            }
            if (Schema::hasColumn('products', 'currency_mode')) {
                $table->dropColumn('currency_mode');
            }
        });
    }
};
