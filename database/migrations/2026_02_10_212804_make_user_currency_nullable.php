<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->change();
            $table->timestamp('currency_selected_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // لضمان نجاح الرجوع إذا كان فيه قيم null
        DB::table('users')->whereNull('currency')->update(['currency' => 'TRY']);
        DB::table('users')->whereNull('currency_selected_at')->update(['currency_selected_at' => now()]);

        Schema::table('users', function (Blueprint $table) {
            $table->char('currency', 3)->nullable(false)->change();
            $table->timestamp('currency_selected_at')->nullable(false)->change();
        });
    }
};
