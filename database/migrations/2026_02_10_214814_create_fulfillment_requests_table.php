<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fulfillment_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('request_id')->nullable(); // من المزود

            $table->string('status')->default('pending'); // pending|success|failed
            $table->integer('http_status')->nullable();

            // PostgreSQL: jsonb (MySQL: تتحول عملياً إلى json)
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fulfillment_requests');
    }
};
