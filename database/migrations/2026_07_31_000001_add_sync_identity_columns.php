<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 0 (sincronización) — Identidad global.
 *
 * Añade a cada tabla sincronizable una identidad independiente del id local:
 *   - `uuid`            identidad única global.
 *   - `origin_node_id`  nodo donde nació el registro.
 *
 * Los ids autoincrementales locales se mantienen para las llaves foráneas
 * internas; el `uuid` es la identidad real entre nodos. Además ensancha
 * `movement.code` para admitir el prefijo de nodo en los códigos legibles.
 */
return new class extends Migration
{
    /**
     * Tablas cuyos registros se sincronizan entre nodos y central.
     *
     * @var array<int, string>
     */
    private array $tables = [
        'category',
        'unit',
        'attribute',
        'attribute_value',
        'supplier',
        'product',
        'warehouse',
        'user',
        'exchange_rate',
        'movement',
        'movement_item',
    ];

    public function up(): void
    {
        $nodeId = config('sync.node_id', 'CENTRAL');

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable()->after('id');
                $t->string('origin_node_id', 60)->nullable()->after('uuid');
            });

            // Backfill de registros existentes: un uuid por fila (generado en PHP
            // para ser portable entre MySQL y SQLite) y el nodo actual como origen.
            foreach (DB::table($table)->whereNull('uuid')->pluck('id') as $id) {
                DB::table($table)->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
            }
            DB::table($table)->whereNull('origin_node_id')->update(['origin_node_id' => $nodeId]);

            Schema::table($table, function (Blueprint $t) {
                $t->unique('uuid');
            });
        }

        // Los códigos de movimiento ahora llevan prefijo de nodo (p. ej. GUA-E-00001).
        Schema::table('movement', function (Blueprint $t) {
            $t->string('code', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('movement', function (Blueprint $t) {
            $t->string('code', 20)->change();
        });

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_uuid_unique');
                $t->dropColumn(['uuid', 'origin_node_id']);
            });
        }
    }
};
