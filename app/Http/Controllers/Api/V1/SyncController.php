<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SyncNode;
use App\Services\Sync\SyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * API de sincronización del servidor central. Solo accesible por nodos
 * autenticados (middleware `sync.node`).
 */
class SyncController extends Controller
{
    public function __construct(private readonly SyncEngine $engine)
    {
    }

    /**
     * Bajada: devuelve, por entidad, los cambios con sync_seq mayor al cursor
     * que envía el nodo. Se excluye lo originado por el propio nodo (ya lo tiene).
     */
    public function pull(Request $request): JsonResponse
    {
        /** @var SyncNode $node */
        $node = $request->attributes->get('sync_node');

        $cursors = (array) $request->input('cursors', []);
        $limit = (int) $request->input('limit', 500);
        $limit = max(1, min($limit, 1000));

        $data = [];
        foreach ($this->engine->pullTypes() as $type) {
            $since = (int) ($cursors[$type] ?? 0);
            $data[$type] = $this->engine->changesFor($type, $since, $node->node_id, $limit);
        }

        // Ajustes globales (tax_rate, datos del negocio): snapshot completo (son pocos).
        $data['setting'] = Setting::query()->get(['key', 'value'])
            ->map(fn (Setting $s) => ['key' => $s->key, 'value' => $s->value])->all();

        // Transferencias entrantes hacia los almacenes de este nodo (para confirmar recepción).
        $data['incoming_transfers'] = $this->engine->incomingTransfersFor($node->node_id);

        return response()->json(['data' => $data]);
    }

    /**
     * Subida: importa (upsert por uuid) los registros que envía el nodo y
     * devuelve el acuse de recibo por entidad.
     */
    public function push(Request $request): JsonResponse
    {
        $payload = (array) $request->input('entities', []);

        $acked = [];
        foreach ($this->engine->pushTypes() as $type) {
            if (! isset($payload[$type]) || ! is_array($payload[$type])) {
                continue;
            }

            $acked[$type] = $this->engine->import($type, $payload[$type]);
        }

        return response()->json(['data' => ['acked' => $acked]]);
    }

    /**
     * Imágenes que el central tiene referenciadas en `product.image` pero cuyo
     * archivo no está en disco. El nodo que las tenga las subirá vía mediaUpload.
     */
    public function mediaMissing(): JsonResponse
    {
        $paths = Product::query()->withoutGlobalScopes()
            ->whereNotNull('image')->where('image', '!=', '')
            ->pluck('image')->unique()->values();

        $missing = $paths->filter(fn ($p) => ! Storage::disk('public')->exists($p))->values();

        return response()->json(['data' => ['missing' => $missing]]);
    }

    /**
     * Recibe el binario de una imagen y lo guarda en la ruta indicada si aún no
     * existe (idempotente). Solo se permiten rutas dentro de `products/`.
     */
    public function mediaUpload(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'image', 'max:10240'],
        ]);

        $path = ltrim(str_replace('\\', '/', (string) $request->input('path')), '/');

        if (str_contains($path, '..') || ! str_starts_with($path, 'products/')) {
            return response()->json([
                'error' => ['code' => 'INVALID_MEDIA_PATH', 'message' => 'Ruta de imagen no permitida.'],
            ], 422);
        }

        $stored = false;
        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->putFileAs(
                dirname($path),
                $request->file('file'),
                basename($path),
            );
            $stored = true;
        }

        return response()->json(['data' => ['stored' => $stored]]);
    }
}
