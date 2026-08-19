<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un nodo (laptop de almacén) autorizado a sincronizar contra el central.
 * `token` guarda el hash del token de máquina; el plano solo se muestra al crearlo.
 */
class SyncNode extends Model
{
    protected $table = 'sync_node';

    protected $fillable = ['node_id', 'name', 'token', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
