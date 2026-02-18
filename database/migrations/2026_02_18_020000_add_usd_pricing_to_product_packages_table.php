<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_packages', function (Blueprint $table) {
            if (!Schema::hasColumn('product_packages', 'cost_usd')) {
                $table->decimal('cost_usd', 24, 10)->nullable();
            }
            if (!Schema::hasColumn('product_packages', 'suggested_usd')) {
                $table->decimal('suggested_usd', 24, 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_packages', function (Blueprint $table) {
            if (Schema::hasColumn('product_packages', 'suggested_usd')) {
                $table->dropColumn('suggested_usd');
            }
            if (Schema::hasColumn('product_packages', 'cost_usd')) {
                $table->dropColumn('cost_usd');
            }
        });
    }
};
