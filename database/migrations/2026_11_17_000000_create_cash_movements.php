<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reference_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('bank_reference_locks')->insert(['id' => 1]);
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('operation_id')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('cash_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 24);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 500);
            $table->unsignedBigInteger('bank_amount_cents')->nullable();
            $table->string('bank_reference', 255)->nullable();
            $table->char('bank_reference_hash', 64)->nullable()->unique();
            $table->string('verification_evidence', 500)->nullable();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['cash_shift_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Cash movement history must not be removed by rollback.');
    }
};
