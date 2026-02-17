<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('price_group_id')
                ->default(1)
                ->constrained('price_groups')
                ->restrictOnDelete();

            $table->index(['price_group_id']);
        });

        // Backfill existing users
        try {
            DB::table('users')->whereNull('price_group_id')->update(['price_group_id' => 1]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['price_group_id']);
            $table->dropConstrainedForeignId('price_group_id');
        });
    }
};
