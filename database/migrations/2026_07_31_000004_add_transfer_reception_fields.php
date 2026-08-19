<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (transferencias) — Recepción en dos fases.
 *
 * Una transferencia sale del origen al crearse (queda `en_transito`) y solo
 * suma stock en el destino cuando se confirma la recepción (`recibido`). Esto
 * refleja la realidad (la mercancía viaja) y permite transferencias entre
 * almacenes que viven en laptops distintas.
 *
 * `warehouse.node_id` indica qué nodo opera cada almacén, para enrutar al
 * destino las transferencias entrantes en la sincronización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movement', function (Blueprint $t) {
            $t->enum('transfer_status', ['en_transito', 'recibido'])->nullable()->after('status');
            $t->timestamp('received_at')->nullable()->after('voided_at');
            $t->foreignId('received_by_user_id')->nullable()->after('voided_by_user_id')->constrained('user');
        });

        // Las transferencias ya existentes se consideran recibidas (comportamiento previo).
        DB::table('movement')->where('type', 'transferencia')->update(['transfer_status' => 'recibido']);

        Schema::table('movement_item', function (Blueprint $t) {
            // Precio de venta previsto para el destino (cuando es tienda); se aplica al recibir.
            $t->decimal('sale_price', 12, 2)->nullable()->after('quantity');
        });

        Schema::table('warehouse', function (Blueprint $t) {
            $t->string('node_id', 60)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('movement', function (Blueprint $t) {
            $t->dropForeign(['received_by_user_id']);
            $t->dropColumn(['transfer_status', 'received_at', 'received_by_user_id']);
        });

        Schema::table('movement_item', function (Blueprint $t) {
            $t->dropColumn('sale_price');
        });

        Schema::table('warehouse', function (Blueprint $t) {
            $t->dropColumn('node_id');
        });
    }
};
