<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            // The database-generated, globally unique id is also the commercial number.
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('operation_id')->unique();
            $table->string('device_id', 100);
            $table->char('request_hash', 64);
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('customer_name', 150);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('total_cents');
            $table->string('payment_status', 24);
            $table->string('kitchen_status', 24)->default('pending');
            $table->timestamps();
            $table->index(['seller_id', 'created_at']);
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->text('notes')->nullable();
        });
        Schema::create('order_item_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('unit_price_cents');
        });
        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('catalog_option_id')->constrained('catalog_options')->restrictOnDelete();
            $table->string('name');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('method', 24);
            $table->string('status', 24);
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_item_extras');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
