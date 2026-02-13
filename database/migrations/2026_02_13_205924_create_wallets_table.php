<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);              // TRY | SYP
            $table->bigInteger('balance_minor')->default(0);

            $table->timestamps();

            $table->unique('user_id');
            $table->index('currency');
        });

        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_currency_chk CHECK (currency IN ('TRY','SYP'))");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_balance_nonneg_chk CHECK (balance_minor >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
