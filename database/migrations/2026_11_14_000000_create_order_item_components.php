<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 80);
            $table->string('notes', 200)->nullable();
            $table->unique(['order_item_id', 'position']);
        });
        Schema::create('order_component_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_component_id')->constrained('order_item_components')->restrictOnDelete();
            $table->foreignId('extra_id')->constrained('extras')->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('unit_price_cents');
            $table->unique(['order_item_component_id', 'extra_id']);
        });
        Schema::create('order_component_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_component_id')->constrained('order_item_components')->restrictOnDelete();
            $table->foreignId('catalog_option_id')->constrained('catalog_options')->restrictOnDelete();
            $table->string('name');
            $table->unique(['order_item_component_id', 'catalog_option_id'], 'order_component_options_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Order component history must not be removed by rollback.');
    }
};
