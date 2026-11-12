<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_price_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->uuid('order_uuid');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('normal_cents');
            $table->unsignedInteger('authorized_cents');
            $table->string('reason', 500);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
            $table->dateTime('used_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->unique()->constrained('order_items')->restrictOnDelete();
            $table->index(['order_uuid', 'seller_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Special price authorization history must not be removed by rollback.');
    }
};
