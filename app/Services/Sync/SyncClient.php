<?php

namespace App\Services\Sync;

use App\Models\Setting;
use App\Models\SyncOutbox;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Cliente de sincronización que corre en cada nodo (laptop). Habla con el API
 * del central: sube (push) los registros locales pendientes del outbox y baja
 * (pull) los cambios consolidados actualizando los cursores por entidad.
 */
class SyncClient
{
    public function __construct(private readonly SyncEngine $engine)
    {
    }

    /**
     * Sube al central los registros locales pendientes (outbox) y limpia los
     * que el central acuse recibo.
     *
     * @return array<string, int>  cantidad subida por entidad
     */
    public function push(): array
    {
        $entities = [];
        foreach ($this->engine->pushTypes() as $type) {
            $records = $this->pendingRecords($type);
            if ($records !== []) {
                $entities[$type] = $records;
            }
        }

        if ($entities === []) {
            return [];
        }

        $response = $this->http()->post('/api/v1/sync/push', ['entities' => $entities]);
        $response->throw();

        $acked = (array) $response->json('data.acked', []);

        $counts = [];
        foreach ($acked as $type => $uuids) {
            if (! is_array($uuids) || $uuids === []) {
                continue;
            }
            SyncOutbox::query()
                ->where('entity_type', $type)
                ->whereIn('entity_uuid', $uuids)
                ->delete();
            $counts[$type] = count($uuids);
        }

        return $counts;
    }

    /**
     * Baja del central los cambios por entidad y avanza los cursores.
     *
     * @return array<string, int>  cantidad bajada por entidad
     */
    public function pull(): array
    {
        $cursors = $this->cursors();

        $response = $this->http()->post('/api/v1/sync/pull', [
            'cursors' => $cursors,
            'limit' => 500,
        ]);
        $response->throw();

        $data = (array) $response->json('data', []);

        $counts = [];
        foreach ($this->engine->pullTypes() as $type) {
            $bucket = $data[$type] ?? null;
            if (! is_array($bucket)) {
                continue;
            }

            $records = $bucket['records'] ?? [];
            if (is_array($records) && $records !== []) {
                $this->engine->import($type, $records);
                $counts[$type] = count($records);
            }

            $maxSeq = (int) ($bucket['max_seq'] ?? 0);
            if ($maxSeq > (int) ($cursors[$type] ?? 0)) {
                $this->setCursor($type, $maxSeq);
            }
        }

        // Ajustes globales: snapshot completo que baja del central.
        $settings = $data['setting'] ?? null;
        if (is_array($settings) && $settings !== []) {
            foreach ($settings as $setting) {
                if (! isset($setting['key'])) {
                    continue;
                }
                Setting::query()->updateOrInsert(
                    ['key' => $setting['key']],
                    ['value' => (string) ($setting['value'] ?? ''), 'updated_at' => now()],
                );
            }
            $counts['setting'] = count($settings);
        }

        // Transferencias entrantes: se importan movimiento e ítems para poder recibirlas.
        $incoming = $data['incoming_transfers'] ?? null;
        if (is_array($incoming)) {
            foreach (['movement', 'movement_item'] as $type) {
                $records = $incoming[$type] ?? [];
                if (is_array($records) && $records !== []) {
                    $this->engine->import($type, $records);
                    $counts['incoming_'.$type] = count($records);
                }
            }
        }

        return $counts;
    }

    /**
     * Sube al central los archivos de imagen (product.image) que le falten en
     * disco y que este nodo tenga localmente. El central indica qué rutas no
     * tiene; es idempotente (no re-sube lo ya presente).
     *
     * @return array<string, int>  cantidad de imágenes subidas
     */
    public function pushMedia(): array
    {
        $response = $this->http()->get('/api/v1/sync/media/missing');
        $response->throw();

        $missing = (array) $response->json('data.missing', []);
        $uploaded = 0;

        foreach ($missing as $path) {
            if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
                continue;
            }

            $upload = $this->http()
                ->attach('file', Storage::disk('public')->get($path), basename($path))
                ->post('/api/v1/sync/media', ['path' => $path]);

            if ($upload->successful() && $upload->json('data.stored') === true) {
                $uploaded++;
            }
        }

        return $uploaded > 0 ? ['images' => $uploaded] : [];
    }

    /**
     * Serializa los registros locales pendientes de una entidad y descarta del
     * outbox los huérfanos (encolados pero cuyo modelo ya no existe).
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingRecords(string $type): array
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = $this->engine->entities()[$type]['model'];

        $queued = SyncOutbox::query()->where('entity_type', $type)->pluck('entity_uuid')->all();
        if ($queued === []) {
            return [];
        }

        $models = $modelClass::query()->withoutGlobalScopes()->whereIn('uuid', $queued)->get();

        $found = $models->pluck('uuid')->all();
        $orphans = array_diff($queued, $found);
        if ($orphans !== []) {
            SyncOutbox::query()->where('entity_type', $type)->whereIn('entity_uuid', $orphans)->delete();
        }

        return $models->map(fn ($m) => $this->engine->serialize($type, $m))->all();
    }

    /** @return array<string, int> */
    private function cursors(): array
    {
        $cursors = [];
        foreach ($this->engine->pullTypes() as $type) {
            $value = DB::table('sync_state')->where('key', "pull_seq:{$type}")->value('value');
            $cursors[$type] = (int) ($value ?? 0);
        }

        return $cursors;
    }

    private function setCursor(string $type, int $value): void
    {
        DB::table('sync_state')->updateOrInsert(
            ['key' => "pull_seq:{$type}"],
            ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function http(): PendingRequest
    {
        $base = rtrim((string) config('sync.central_url'), '/');

        return Http::baseUrl($base)
            ->acceptJson()
            ->timeout(30)
            ->withHeaders([
                'X-Sync-Node' => (string) config('sync.node_id'),
                'X-Sync-Token' => (string) config('sync.node_token'),
            ]);
    }
}
