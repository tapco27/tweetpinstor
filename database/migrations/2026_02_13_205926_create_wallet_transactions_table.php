<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('direction');                 // credit | debit
            $table->string('status')->default('posted'); // pending | posted | reversed
            $table->string('type');                      // topup | order_payment | refund | adjustment

            $table->bigInteger('amount_minor');          // > 0 دائماً
            $table->bigInteger('balance_after_minor')->nullable();

            $table->string('reference_type');            // order | wallet_topup | ...
            $table->unsignedBigInteger('reference_id');  // internal id
            $table->uuid('reference_uuid')->nullable();  // optional (زيادة أمان idempotency)

            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);

            // يمنع تكرار نفس العملية
            $table->unique(['wallet_id', 'reference_type', 'reference_id', 'direction', 'type'], 'wallet_tx_unique_ref');
        });

        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_tx_direction_chk CHECK (direction IN ('credit','debit'))");
        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_tx_status_chk CHECK (status IN ('pending','posted','reversed'))");
        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_tx_amount_pos_chk CHECK (amount_minor > 0)");
        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_tx_balance_nonneg_chk CHECK (balance_after_minor IS NULL OR balance_after_minor >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
