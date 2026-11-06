<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('kitchen_status', 24)->default('NEW')->change();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->index(['updated_at', 'id']);
        });
        DB::table('orders')->where('kitchen_status', 'pending')->update(['kitchen_status' => 'NEW']);
        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->uuid('order_uuid');
            $table->string('previous_status', 24);
            $table->string('new_status', 24);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->timestamp('created_at');
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Kitchen history must not be removed by rollback.');
    }
};
