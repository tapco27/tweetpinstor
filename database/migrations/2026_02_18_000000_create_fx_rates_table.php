<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 16)->unique(); // USD_TRY, USD_SYP
            $table->string('base_currency', 3)->default('USD');
            $table->string('quote_currency', 3);
            $table->decimal('rate', 24, 10);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
