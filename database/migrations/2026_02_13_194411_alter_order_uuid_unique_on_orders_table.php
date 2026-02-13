<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // كان unique(order_uuid)
            $table->dropUnique(['order_uuid']);
            // صار unique(user_id, order_uuid)
            $table->unique(['user_id', 'order_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'order_uuid']);
            $table->unique('order_uuid');
        });
    }
};
