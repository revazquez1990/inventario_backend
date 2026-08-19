<?php

namespace App\Services\Sync;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Núcleo del motor de sincronización. Traduce registros entre nodos usando el
 * `uuid` como identidad global: las relaciones (FKs) viajan como "<fk>_uuid" y
 * al importar se resuelven al id local correspondiente. Todo el upsert es
 * idempotente por uuid, de modo que reenviar un lote nunca duplica.
 *
 * Se apoya en el registro `config('sync.entities')`.
 */
class SyncEngine
{
    /** @return array<string, array<string, mixed>> */
    public function entities(): array
    {
        return config('sync.entities', []);
    }

    /** Tipos que fluyen del central al nodo (el nodo los baja). @return array<int, string> */
    public function pullTypes(): array
    {
        return $this->typesWithDirection(['down', 'both']);
    }

    /** Tipos que fluyen del nodo al central (el nodo los sube). @return array<int, string> */
    public function pushTypes(): array
    {
        return $this->typesWithDirection(['up', 'both']);
    }

    /**
     * Devuelve los cambios de una entidad con sync_seq mayor al cursor dado.
     *
     * @return array{records: array<int, array<string, mixed>>, max_seq: int, has_more: bool}
     */
    public function changesFor(string $type, int $sinceSeq, ?string $excludeNodeId, int $limit = 500): array
    {
        $model = $this->modelClass($type);

        $query = $model::query()->withoutGlobalScopes()
            ->whereNotNull('sync_seq')
            ->where('sync_seq', '>', $sinceSeq);

        if ($excludeNodeId !== null) {
            $query->where('origin_node_id', '!=', $excludeNodeId);
        }

        $rows = $query->orderBy('sync_seq')->limit($limit)->get();

        return [
            'records' => $rows->map(fn (Model $m) => $this->serialize($type, $m))->all(),
            'max_seq' => (int) ($rows->max('sync_seq') ?? $sinceSeq),
            'has_more' => $rows->count() === $limit,
        ];
    }

    /**
     * Transferencias en tránsito cuyo destino es un almacén operado por el nodo
     * dado (por `warehouse.node_id`) y que se originaron en otro nodo. Se devuelven
     * el movimiento y sus ítems para que el nodo destino pueda confirmar la recepción.
     *
     * @return array{movement: array<int, array<string, mixed>>, movement_item: array<int, array<string, mixed>>}
     */
    public function incomingTransfersFor(string $nodeId): array
    {
        $warehouseIds = \App\Models\Warehouse::query()->withoutGlobalScopes()
            ->where('node_id', $nodeId)->pluck('id')->all();

        if ($warehouseIds === []) {
            return ['movement' => [], 'movement_item' => []];
        }

        $movements = \App\Models\Movement::query()
            ->whereIn('to_warehouse_id', $warehouseIds)
            ->where('type', 'transferencia')
            ->where('status', 'activo')
            ->where('transfer_status', 'en_transito')
            ->where('origin_node_id', '!=', $nodeId)
            ->with('items')
            ->get();

        $items = [];
        foreach ($movements as $movement) {
            foreach ($movement->items as $item) {
                $items[] = $this->serialize('movement_item', $item);
            }
        }

        return [
            'movement' => $movements->map(fn ($m) => $this->serialize('movement', $m))->all(),
            'movement_item' => $items,
        ];
    }

    /**
     * Importa (upsert por uuid) un lote de registros de una entidad.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, string>  uuids efectivamente aplicados (acuse de recibo)
     */
    public function import(string $type, array $records): array
    {
        $def = $this->entities()[$type];
        $acked = [];

        foreach ($records as $record) {
            try {
                $this->upsert($type, $def, $record);
                $acked[] = $record['uuid'];
            } catch (\Throwable $e) {
                // Se omite este registro; se reintentará en la próxima sincronización.
                report($e);
            }
        }

        return $acked;
    }

    /**
     * Serializa un modelo a su forma de transporte: columnas propias + uuid de
     * cada relación + identidad.
     *
     * @return array<string, mixed>
     */
    public function serialize(string $type, Model $model): array
    {
        $def = $this->entities()[$type];

        $row = [
            'uuid' => $model->uuid,
            'origin_node_id' => $model->origin_node_id,
        ];

        foreach ($def['columns'] as $column) {
            $row[$column] = $this->toScalar($model->getAttribute($column));
        }

        foreach ($def['relations'] as $foreignKey => $relatedType) {
            $row[$foreignKey.'_uuid'] = $this->foreignUuid($relatedType, $model->getAttribute($foreignKey));
        }

        // Pivotes embebidos: lista de uuids de los registros relacionados.
        foreach ($def['links'] ?? [] as $payloadKey => $link) {
            $relatedTable = $this->modelInstance($link['related'])->getTable();
            $row[$payloadKey] = $model->{$link['relation']}()->pluck($relatedTable.'.uuid')->all();
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $record
     */
    private function upsert(string $type, array $def, array $record): void
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $def['model'];

        $attributes = [];
        foreach ($def['columns'] as $column) {
            if (array_key_exists($column, $record)) {
                $attributes[$column] = $record[$column];
            }
        }

        foreach ($def['relations'] as $foreignKey => $relatedType) {
            $attributes[$foreignKey] = $this->resolveId($relatedType, $record[$foreignKey.'_uuid'] ?? null);
        }

        $attributes['origin_node_id'] = $record['origin_node_id'] ?? null;

        // El hash de contraseña se persiste en crudo para no re-hashearlo (cast 'hashed').
        $rawPassword = null;
        if ($type === 'user' && array_key_exists('password', $attributes)) {
            $rawPassword = $attributes['password'];
            unset($attributes['password']);
        }

        $model = $modelClass::query()->withoutGlobalScopes()->where('uuid', $record['uuid'])->first();

        if ($model === null) {
            $model = new $modelClass;
            $model->uuid = $record['uuid'];
            // La columna password es NOT NULL (sin default): en un alta ponemos un
            // placeholder para satisfacer el INSERT; el hash real se escribe abajo
            // en crudo (bypass del cast 'hashed') sobrescribiendo este valor.
            if ($rawPassword !== null) {
                $attributes['password'] = 'sync-placeholder';
            }
        }

        $model->forceFill($attributes)->save();

        if ($rawPassword !== null) {
            DB::table($model->getTable())->where('id', $model->getKey())->update(['password' => $rawPassword]);
        }

        // Pivotes embebidos: se reemplaza el set completo (resolviendo uuid -> id local).
        foreach ($def['links'] ?? [] as $payloadKey => $link) {
            if (! array_key_exists($payloadKey, $record)) {
                continue;
            }

            $relatedTable = $this->modelInstance($link['related'])->getTable();
            $ids = DB::table($relatedTable)
                ->whereIn('uuid', (array) $record[$payloadKey])
                ->pluck('id')->all();

            $model->{$link['relation']}()->sync($ids);
        }
    }

    private function resolveId(string $relatedType, ?string $uuid): ?int
    {
        if ($uuid === null) {
            return null;
        }

        $table = $this->modelInstance($relatedType)->getTable();

        $id = DB::table($table)->where('uuid', $uuid)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function foreignUuid(string $relatedType, mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $table = $this->modelInstance($relatedType)->getTable();

        return DB::table($table)->where('id', $id)->value('uuid');
    }

    private function toScalar(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /** @param  array<int, string>  $directions @return array<int, string> */
    private function typesWithDirection(array $directions): array
    {
        $types = [];
        foreach ($this->entities() as $type => $def) {
            if (in_array($def['direction'], $directions, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** @return class-string<Model> */
    private function modelClass(string $type): string
    {
        return $this->entities()[$type]['model'];
    }

    private function modelInstance(string $type): Model
    {
        $class = $this->modelClass($type);

        return new $class;
    }
}
