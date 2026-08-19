<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncClient;
use Illuminate\Console\Command;

/**
 * Sincroniza este nodo con el servidor central. Por defecto sube y luego baja;
 * con --push o --pull se limita a una dirección. Se puede disparar manualmente,
 * por el scheduler, o desde el frontend (endpoint que lo invoca) — ver Fase 3.
 */
class SyncRun extends Command
{
    protected $signature = 'sync:run {--push : Solo subir al central} {--pull : Solo bajar del central}';

    protected $description = 'Sincroniza este nodo con el servidor central (sube movimientos, baja catálogo).';

    public function handle(SyncClient $client): int
    {
        if (config('sync.role') !== 'node') {
            $this->error('Esta instalación no es un nodo (config sync.role != "node").');

            return self::FAILURE;
        }

        if (! config('sync.central_url') || ! config('sync.node_token')) {
            $this->error('Falta configurar SYNC_CENTRAL_URL y/o SYNC_NODE_TOKEN.');

            return self::FAILURE;
        }

        $onlyPush = (bool) $this->option('push');
        $onlyPull = (bool) $this->option('pull');
        $doPush = $onlyPush || ! $onlyPull;
        $doPull = $onlyPull || ! $onlyPush;

        try {
            if ($doPush) {
                $result = $client->push();
                $this->info('Subida: '.($result === [] ? 'nada pendiente' : json_encode($result)));
            }

            if ($doPull) {
                $result = $client->pull();
                $this->info('Bajada: '.($result === [] ? 'sin cambios' : json_encode($result)));
            }
        } catch (\Throwable $e) {
            $this->error('No se pudo sincronizar con el central: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sincronización completada.');

        return self::SUCCESS;
    }
}
