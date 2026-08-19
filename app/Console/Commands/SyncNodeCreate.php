<?php

namespace App\Console\Commands;

use App\Models\SyncNode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * (Central) Da de alta o rota un nodo de sincronización y muestra su token de
 * máquina en claro una sola vez. Ese token va en el .env del nodo como
 * SYNC_NODE_TOKEN.
 */
class SyncNodeCreate extends Command
{
    protected $signature = 'sync:node:create {node_id : Id único del nodo, p.ej. GUANABACOA} {name? : Nombre visible}';

    protected $description = 'Crea o rota un nodo de sincronización y muestra su token (solo se ve una vez).';

    public function handle(): int
    {
        if (config('sync.role') !== 'central') {
            $this->warn('Aviso: esta instalación no está marcada como central (sync.role != "central").');
        }

        $nodeId = (string) $this->argument('node_id');
        $name = (string) ($this->argument('name') ?? $nodeId);

        $token = Str::random(48);

        SyncNode::query()->updateOrCreate(
            ['node_id' => $nodeId],
            ['name' => $name, 'token' => Hash::make($token)],
        );

        $this->info("Nodo '{$nodeId}' listo.");
        $this->newLine();
        $this->line('Configura en el .env del nodo:');
        $this->line("  SYNC_NODE_ID={$nodeId}");
        $this->line('  SYNC_NODE_TOKEN='.$token);
        $this->newLine();
        $this->warn('Este token no se volverá a mostrar. Si lo pierdes, vuelve a ejecutar este comando para rotarlo.');

        return self::SUCCESS;
    }
}
