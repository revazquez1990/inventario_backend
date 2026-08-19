<?php

namespace App\Models\Concerns;

use App\Models\SyncOutbox;
use App\Support\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Da a cada registro una identidad global e independiente del id autoincremental
 * local y lo integra al motor de sincronización:
 *
 *  - `uuid`            identidad única global (se genera al crear si falta).
 *  - `origin_node_id`  nodo donde nació el registro (config `sync.node_id`).
 *  - `sync_seq`        (solo en el central) cursor global creciente asignado en
 *                      cada escritura; los nodos bajan por este cursor.
 *
 * Además, en los nodos (role = 'node') encola en `sync_outbox` cada registro
 * de origen local para subirlo al central en el próximo push.
 *
 * Al importar registros de otro nodo, la capa de sincronización setea uuid y
 * origin explícitamente, por lo que aquí solo se asignan cuando vienen vacíos.
 */
trait HasSyncIdentity
{
    public static function bootHasSyncIdentity(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->origin_node_id)) {
                $model->origin_node_id = config('sync.node_id');
            }
        });

        // El central estampa un cursor global en cada escritura para que los
        // nodos puedan bajar los cambios de forma incremental y ordenada.
        static::saving(function ($model) {
            if (config('sync.role') === 'central') {
                $model->sync_seq = SyncSequence::next();
            }
        });

        // Los nodos encolan sus propios cambios (origen local) para subirlos.
        static::saved(function ($model) {
            if (config('sync.role') !== 'node') {
                return;
            }

            if ($model->origin_node_id !== config('sync.node_id')) {
                return; // Registro bajado del central: no se reenvía.
            }

            // Solo se encolan entidades que el nodo realmente sube (up/both). Una
            // entidad 'down' (p.ej. exchange_rate) creada localmente no puede subir
            // y quedaría atascada para siempre en el outbox ("N por subir" eterno).
            $direction = config('sync.entities.'.$model->getTable().'.direction');
            if (! in_array($direction, ['up', 'both'], true)) {
                return;
            }

            SyncOutbox::query()->updateOrInsert(
                ['entity_type' => $model->getTable(), 'entity_uuid' => $model->uuid],
                ['queued_at' => now()],
            );
        });
    }
}
