<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('open_operation_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('opening_cents');
            $table->unsignedBigInteger('cash_sales_cents')->nullable();
            $table->unsignedBigInteger('expected_cents')->nullable();
            $table->unsignedBigInteger('counted_cents')->nullable();
            $table->bigInteger('difference_cents')->nullable();
            $table->uuid('close_operation_id')->nullable()->unique();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'opened_at']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cash history must not be removed by rollback.');
    }
};
