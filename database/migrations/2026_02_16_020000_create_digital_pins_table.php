<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('digital_pins', function (Blueprint $table) {
      $table->id();

      $table->foreignId('product_id')->constrained()->cascadeOnDelete();
      $table->foreignId('package_id')->nullable()->constrained('product_packages')->nullOnDelete();

      // Inventory bucket key. For fixed_package we use ProductPackage.value_label (e.g. "100 TRY").
      $table->string('inventory_key', 191);

      // Store encrypted code, and a deterministic hash for uniqueness.
      $table->text('code_encrypted');
      $table->string('code_hash', 64);

      // available | sold
      $table->string('status', 20)->default('available');

      // When sold, link to order.
      $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
      $table->timestamp('sold_at')->nullable();

      // Optional metadata (batch name, provider, notes...)
      $table->jsonb('metadata')->nullable();

      // Who added this pin (admin user)
      $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

      $table->timestamps();

      $table->unique('code_hash');
      $table->index(['product_id', 'inventory_key', 'status']);
      $table->index(['order_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('digital_pins');
  }
};
