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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // e.g. "google", "apple"
            $table->string('provider', 32);

            // e.g. Google "sub" / Apple "sub"
            $table->string('provider_user_id', 191);

            // Apple may return relay email; keep it for reference
            $table->string('email')->nullable();

            // Optional snapshot of provider payload (sanitized)
            $table->json('payload')->nullable();

            $table->timestamps();

            // One provider account maps to one user
            $table->unique(['provider', 'provider_user_id']);

            // One user can have at most one account per provider
            $table->unique(['user_id', 'provider']);

            $table->index(['provider', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
