<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 1.1 (sincronización) — Stock sincronizable.
 *
 * Da identidad global y cursor a `product_warehouse` para poder sincronizar el
 * stock (y el sale_price de tiendas) directamente. Es seguro porque cada almacén
 * tiene un único escritor (su nodo): no hay conflictos entre nodos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nodeId = config('sync.node_id', 'CENTRAL');

        Schema::table('product_warehouse', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->after('id');
            $t->string('origin_node_id', 60)->nullable()->after('uuid');
            $t->unsignedBigInteger('sync_seq')->nullable()->index()->after('origin_node_id');
        });

        foreach (DB::table('product_warehouse')->whereNull('uuid')->pluck('id') as $id) {
            $seq = DB::table('sync_sequence')->insertGetId(['created_at' => now()]);
            DB::table('product_warehouse')->where('id', $id)->update([
                'uuid' => (string) Str::uuid(),
                'origin_node_id' => $nodeId,
                'sync_seq' => $seq,
            ]);
        }

        Schema::table('product_warehouse', function (Blueprint $t) {
            $t->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('product_warehouse', function (Blueprint $t) {
            $t->dropUnique('product_warehouse_uuid_unique');
            $t->dropColumn(['uuid', 'origin_node_id', 'sync_seq']);
        });
    }
};
