<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('provider_integrations', function (Blueprint $table) {
      $table->id();

      // References config/provider_templates.php
      $table->string('template_code', 191);

      // Friendly name for admin UI (e.g. "FreeKasa Account 1")
      $table->string('name');

      // Encrypted JSON blob containing credentials (username/password/api_key...)
      $table->text('credentials_encrypted')->nullable();

      $table->boolean('is_active')->default(true);

      // Optional metadata (notes, base_url override, etc)
      $table->jsonb('meta')->nullable();

      // Who created it
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

      $table->timestamps();

      $table->index(['template_code', 'is_active']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('provider_integrations');
  }
};
