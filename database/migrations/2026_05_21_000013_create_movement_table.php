<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['entrada', 'salida', 'venta', 'ajuste', 'anulacion', 'transferencia'])->index();
            $table->enum('adjustment_subtype', ['merma', 'rotura', 'conteo_fisico'])->nullable();
            $table->string('code', 20)->unique();
            $table->enum('status', ['activo', 'anulado'])->default('activo')->index();

            // snapshots
            $table->decimal('exchange_rate_snapshot', 12, 4);
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rate');
            $table->decimal('tax_rate_snapshot', 5, 2);

            // contextuales
            $table->foreignId('supplier_id')->nullable()->constrained('supplier');
            $table->foreignId('original_movement_id')->nullable()->constrained('movement');

            // motivos
            $table->text('reason')->nullable();
            $table->text('reason_void')->nullable();

            // totales (denormalizados para reportes)
            $table->decimal('total_without_tax_usd', 14, 2)->default(0);
            $table->decimal('total_tax_usd', 14, 2)->default(0);
            $table->decimal('total_with_tax_usd', 14, 2)->default(0);
            $table->decimal('total_without_tax_cup', 16, 2)->default(0);
            $table->decimal('total_tax_cup', 16, 2)->default(0);
            $table->decimal('total_with_tax_cup', 16, 2)->default(0);

            // auditoría
            $table->foreignId('created_by_user_id')->constrained('user');
            $table->foreignId('voided_by_user_id')->nullable()->constrained('user');
            $table->timestamp('voided_at')->nullable();

            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement');
    }
};
