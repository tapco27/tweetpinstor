<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();

            $table->uuid('topup_uuid')->unique();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('payment_method_id')->nullable()
                ->constrained('payment_methods')->nullOnDelete();

            $table->char('currency', 3);                 // TRY | SYP
            $table->bigInteger('amount_minor');          // > 0

            $table->string('status')->default('pending_review');
            // pending_review | approved | rejected | posted | failed | canceled

            // بيانات مطلوبة للفواتير/التدقيق
            $table->string('payer_full_name');
            $table->string('national_id');
            $table->string('phone');

            // الإيصال
            $table->text('receipt_note');                // نص الإيصال
            $table->string('receipt_image_path');        // مسار الصورة داخل private disk

            // مراجعة الأدمن
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();     // سبب رفض / ملاحظة

            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
        });

        // PostgreSQL-only constraints (SQLite tests / MySQL compatibility)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wallet_topups ADD CONSTRAINT wallet_topups_currency_chk CHECK (currency IN ('TRY','SYP'))");
            DB::statement("ALTER TABLE wallet_topups ADD CONSTRAINT wallet_topups_amount_pos_chk CHECK (amount_minor > 0)");
            DB::statement("ALTER TABLE wallet_topups ADD CONSTRAINT wallet_topups_status_chk CHECK (status IN ('pending_review','approved','rejected','posted','failed','canceled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
    }
};
