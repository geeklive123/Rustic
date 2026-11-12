<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('cash_shifts')->whereNull('closed_at')->count() > 1) {
            throw new RuntimeException('Hay varios turnos abiertos; deben cerrarse antes de activar la caja compartida.');
        }

        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_global')->nullable()->unique();
        });
        DB::table('cash_shifts')->whereNull('closed_at')->update(['active_global' => 1]);
    }

    public function down(): void
    {
        throw new RuntimeException('Shared cash history must not be altered by rollback.');
    }
};
