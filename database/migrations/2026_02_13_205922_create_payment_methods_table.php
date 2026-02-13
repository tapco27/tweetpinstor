<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();          // bank_transfer, external_wallet, ...
            $table->string('name');                   // الاسم الظاهر للمستخدم
            $table->string('type');                   // manual | gateway
            $table->string('scope')->default('both');  // topup | order | both

            $table->char('currency', 3)->nullable();   // TRY | SYP | null = all
            $table->text('instructions')->nullable();  // IBAN / اسم المستلم / ...
            $table->boolean('is_active')->default(true);

            $table->jsonb('config')->nullable();       // لاحقاً (بوابات دفع)
            $table->timestamps();

            $table->index(['type', 'scope', 'currency', 'is_active']);
        });

        // (اختياري) check constraints على PostgreSQL
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_type_chk CHECK (type IN ('manual','gateway'))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_scope_chk CHECK (scope IN ('topup','order','both'))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_currency_chk CHECK (currency IS NULL OR currency IN ('TRY','SYP'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
