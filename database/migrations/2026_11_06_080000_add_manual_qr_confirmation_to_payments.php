<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('confirmation_operation_id')->nullable()->unique();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('reference', 255)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Payment confirmation history must not be removed by rollback.');
    }
};
