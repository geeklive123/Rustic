<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('original_movement_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
            $table->bigInteger('cash_adjustment_cents')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Compensation history must not be removed by rollback.');
    }
};
