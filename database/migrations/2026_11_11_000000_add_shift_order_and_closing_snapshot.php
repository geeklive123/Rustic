<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();
        });
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('opening_qr_cents')->default(0);
            $table->text('close_note')->nullable();
            $table->json('close_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cash history must not be removed by rollback.');
    }
};
