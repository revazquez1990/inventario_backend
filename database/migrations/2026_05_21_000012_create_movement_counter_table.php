<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_counter', function (Blueprint $table) {
            $table->enum('type', ['entrada', 'salida', 'venta', 'ajuste', 'anulacion'])->primary();
            $table->unsignedInteger('next_value')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_counter');
    }
};
