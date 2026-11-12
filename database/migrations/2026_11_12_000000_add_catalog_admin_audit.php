<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_price_changes', function (Blueprint $table) {
            $table->id();
            $table->string('item_type', 16);
            $table->unsignedBigInteger('item_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('old_cents');
            $table->unsignedInteger('new_cents');
            $table->timestamp('created_at');
            $table->index(['item_type', 'item_id', 'created_at']);
        });
        Schema::create('catalog_web_operations', function (Blueprint $table) {
            $table->uuid('operation_id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->string('item_type', 16);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Catalog audit and operation history must not be removed by rollback.');
    }
};
