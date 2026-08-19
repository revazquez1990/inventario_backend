<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyncOutbox;
use App\Services\Sync\SyncClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints locales de sincronización, para que el frontend del propio nodo
 * consulte el estado y dispare una sincronización. Se autentican con el JWT del
 * usuario (no con el token de máquina): esto NO habla con el central por sí
 * mismo, sino que orquesta al SyncClient del nodo.
 */
class SyncLocalController extends Controller
{
    public function status(): JsonResponse
    {
        $pendingByEntity = SyncOutbox::query()
            ->selectRaw('entity_type, count(*) as total')
            ->groupBy('entity_type')
            ->pluck('total', 'entity_type');

        return response()->json(['data' => [
            'role' => config('sync.role'),
            'node_id' => config('sync.node_id'),
            'central_configured' => (bool) (config('sync.central_url') && config('sync.node_token')),
            'pending_total' => (int) SyncOutbox::query()->count(),
            'pending_by_entity' => $pendingByEntity,
            'last_sync_at' => DB::table('sync_state')->max('updated_at'),
        ]]);
    }

    public function run(SyncClient $client): JsonResponse
    {
        if (config('sync.role') !== 'node') {
            return response()->json([
                'error' => ['code' => 'NOT_A_NODE', 'message' => 'Esta instalación no es un nodo de almacén.'],
            ], 422);
        }

        if (! config('sync.central_url') || ! config('sync.node_token')) {
            return response()->json([
                'error' => ['code' => 'SYNC_NOT_CONFIGURED', 'message' => 'Falta configurar la conexión con el central.'],
            ], 422);
        }

        try {
            $pushed = $client->push();
            $mediaUp = $client->pushMedia();
            $pulled = $client->pull();
            $mediaDown = $client->pullMedia();
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'SYNC_FAILED', 'message' => 'No se pudo sincronizar con el central: '.$e->getMessage()],
            ], 502);
        }

        return response()->json(['data' => [
            'pushed' => $pushed,
            'pulled' => $pulled,
            'media' => ['up' => $mediaUp, 'down' => $mediaDown],
        ]]);
    }
}
