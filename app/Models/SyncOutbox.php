<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cola local (solo en nodos) de registros creados/modificados localmente que
 * están pendientes de subir al central. Se llena desde HasSyncIdentity y se
 * vacía cuando el central acusa recibo.
 */
class SyncOutbox extends Model
{
    protected $table = 'sync_outbox';

    public $timestamps = false;

    protected $fillable = ['entity_type', 'entity_uuid', 'queued_at'];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
        ];
    }
}
