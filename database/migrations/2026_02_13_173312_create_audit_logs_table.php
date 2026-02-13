<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('actor');     // actor_type, actor_id
            $table->morphs('auditable');         // auditable_type, auditable_id

            $table->string('action');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('meta')->nullable();

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
