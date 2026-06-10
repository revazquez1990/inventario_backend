<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The single concrete location resolved by the `warehouse` middleware, or
     * null when the request targets an aggregate scope ("all" / "all-tiendas").
     */
    protected function resolvedWarehouseId(Request $request): ?int
    {
        $value = $request->attributes->get('warehouse_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * The set of location ids the request reads/aggregates over.
     *
     * @return array<int, int>
     */
    protected function resolvedWarehouseIds(Request $request): array
    {
        return (array) $request->attributes->get('warehouse_ids', []);
    }
}
