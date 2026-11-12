<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('combo_revision')->default(0);
        });
        Schema::create('catalog_combo_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedTinyInteger('quantity');
            $table->boolean('customizable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'is_active']);
        });
        Schema::create('combo_component_extra', function (Blueprint $table) {
            $table->foreignId('component_id')->constrained('catalog_combo_components')->restrictOnDelete();
            $table->foreignId('extra_id')->constrained('extras')->restrictOnDelete();
            $table->primary(['component_id', 'extra_id']);
        });
        Schema::create('combo_component_option', function (Blueprint $table) {
            $table->foreignId('component_id')->constrained('catalog_combo_components')->restrictOnDelete();
            $table->foreignId('option_id')->constrained('catalog_options')->restrictOnDelete();
            $table->primary(['component_id', 'option_id']);
        });
        Schema::table('order_item_components', function (Blueprint $table) {
            $table->foreignId('catalog_component_id')->nullable()->constrained('catalog_combo_components')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Combo definitions and order snapshots must not be removed by rollback.');
    }
};
