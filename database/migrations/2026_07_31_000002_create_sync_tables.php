<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 (sincronización) — Infraestructura del motor de sync.
 *
 *  - `sync_node`      (central) nodos autorizados a sincronizar + su token.
 *  - `sync_sequence`  (central) secuencia global monotónica: cada valor es el
 *                     cursor `sync_seq` de un registro aceptado por el central.
 *  - `sync_state`     (nodo) cursores por entidad de la última bajada (pull).
 *  - `sync_outbox`    (nodo) cola de registros locales pendientes de subir (push).
 *  - `sync_seq`       columna en cada tabla sincronizable con el cursor del central.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'category', 'unit', 'attribute', 'attribute_value', 'supplier',
        'product', 'warehouse', 'user', 'exchange_rate', 'movement', 'movement_item',
    ];

    public function up(): void
    {
        Schema::create('sync_node', function (Blueprint $t) {
            $t->id();
            $t->string('node_id', 60)->unique();
            $t->string('name', 120);
            $t->string('token');                 // hash del token de máquina
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('sync_sequence', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sync_state', function (Blueprint $t) {
            $t->string('key', 120)->primary();   // p.ej. "pull_seq:product"
            $t->string('value', 190)->nullable();
            $t->timestamps();
        });

        Schema::create('sync_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('entity_type', 60);
            $t->uuid('entity_uuid');
            $t->timestamp('queued_at')->nullable();
            $t->unique(['entity_type', 'entity_uuid']);
        });

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('sync_seq')->nullable()->index();
            });

            // Backfill: da un sync_seq a los registros ya existentes (catálogo/usuarios
            // sembrados en el central) para que los nodos puedan bajarlos.
            foreach (DB::table($table)->orderBy('id')->pluck('id') as $id) {
                $seq = DB::table('sync_sequence')->insertGetId(['created_at' => now()]);
                DB::table($table)->where('id', $id)->update(['sync_seq' => $seq]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('sync_seq');
            });
        }

        Schema::dropIfExists('sync_outbox');
        Schema::dropIfExists('sync_state');
        Schema::dropIfExists('sync_sequence');
        Schema::dropIfExists('sync_node');
    }
};
