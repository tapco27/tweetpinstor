<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. default, vip, reseller_a
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['is_active']);
        });

        // Ensure default group exists with id=1
        DB::table('price_groups')->insert([
            'id' => 1,
            'code' => 'default',
            'name' => 'Default',
            'is_active' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Postgres: advance sequence to MAX(id) to avoid duplicate key on next insert.
        try {
            DB::statement("SELECT setval(pg_get_serial_sequence('price_groups','id'), (SELECT COALESCE(MAX(id), 1) FROM price_groups))");
        } catch (\Throwable $e) {
            // ignore (MySQL/SQLite)
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_groups');
    }
};
