<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_admin_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->string('action', 32);
            $table->text('before_value')->nullable();
            $table->text('after_value')->nullable();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Order audit history must not be removed by rollback.');
    }
};
