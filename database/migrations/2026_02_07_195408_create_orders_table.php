<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('orders', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained()->cascadeOnDelete();

      $table->char('currency', 3); // TRY | SYP
      $table->string('status')->default('pending'); 
      // pending | paid | failed | delivered | canceled | refunded | pending_payment

      $table->unsignedBigInteger('subtotal_amount_minor');
      $table->unsignedBigInteger('fees_amount_minor')->default(0);
      $table->unsignedBigInteger('total_amount_minor');

      $table->string('payment_provider')->default('stripe'); // stripe/manual
      $table->string('stripe_payment_intent_id')->nullable();
      $table->string('stripe_latest_event_id')->nullable();

      $table->timestamps();

      $table->index(['user_id','status','created_at']);
      $table->index(['currency','status']);
      $table->unique(['stripe_payment_intent_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('orders');
  }
};
