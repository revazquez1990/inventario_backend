<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active warehouse for the request from the `X-Warehouse-Id`
 * header (numeric id, or `all`) and authorizes it against the user's access.
 *
 * Exposes two request attributes for controllers:
 *  - `warehouse_id`  int|null  the concrete warehouse, or null when scope is "all"
 *  - `warehouse_all` bool      true when the request targets every warehouse
 */
class ResolveWarehouse
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'No autenticado.', 401);
        }

        $header = trim((string) $request->header('X-Warehouse-Id', ''));
        $accessible = $user->accessibleWarehouseIds();

        // "All warehouses" scope — admin only.
        if ($header === 'all') {
            if (! $user->isAdmin()) {
                return $this->error('FORBIDDEN', 'No tienes permisos para ver todos los almacenes.', 403);
            }

            $request->attributes->set('warehouse_id', null);
            $request->attributes->set('warehouse_all', true);

            return $next($request);
        }

        // Explicit warehouse id.
        if ($header !== '') {
            $warehouseId = (int) $header;

            if (! $user->canAccessWarehouse($warehouseId)) {
                return $this->error('FORBIDDEN', 'No tienes acceso a este almacén.', 403);
            }

            $request->attributes->set('warehouse_id', $warehouseId);
            $request->attributes->set('warehouse_all', false);

            return $next($request);
        }

        // No header: admin defaults to "all", almacenero to their first warehouse.
        if ($user->isAdmin()) {
            $request->attributes->set('warehouse_id', null);
            $request->attributes->set('warehouse_all', true);

            return $next($request);
        }

        $request->attributes->set('warehouse_id', $accessible[0] ?? null);
        $request->attributes->set('warehouse_all', false);

        return $next($request);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
