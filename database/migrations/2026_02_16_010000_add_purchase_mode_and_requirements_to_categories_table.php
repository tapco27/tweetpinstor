<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // fixed_package | flexible_quantity
            $table->string('purchase_mode', 50)->nullable()->after('requirement_key');

            /**
             * Purchase metadata requirements.
             * Example: ["uid"] or ["email"] or ["player_id","server_id"].
             */
            $table->json('requirements')->nullable()->after('purchase_mode');

            $table->index('purchase_mode');
        });

        // Backfill requirements from legacy requirement_key (DB-agnostic)
        $rows = DB::table('categories')
            ->select(['id', 'requirement_key', 'requirements'])
            ->whereNotNull('requirement_key')
            ->get();

        foreach ($rows as $row) {
            $hasReq = $row->requirements !== null && trim((string) $row->requirements) !== '';
            if ($hasReq) {
                continue;
            }
            $key = trim((string) $row->requirement_key);
            if ($key === '') {
                continue;
            }
            DB::table('categories')
                ->where('id', $row->id)
                ->update(['requirements' => json_encode([$key])]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['purchase_mode']);
            $table->dropColumn(['purchase_mode', 'requirements']);
        });
    }
};
