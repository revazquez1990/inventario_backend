<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The concrete warehouse resolved by the `warehouse` middleware, or null
     * when the request targets every warehouse ("all" scope).
     */
    protected function resolvedWarehouseId(Request $request): ?int
    {
        $value = $request->attributes->get('warehouse_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * Whether the request targets every accessible warehouse.
     */
    protected function isAllWarehouses(Request $request): bool
    {
        return (bool) $request->attributes->get('warehouse_all', false);
    }
}
