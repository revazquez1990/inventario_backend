<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active location scope for the request from the `X-Warehouse-Id`
 * header and authorizes it against the user's access. Accepted values:
 *  - a numeric id  -> a single concrete location
 *  - `all`         -> every accessible warehouse (kind = almacen)
 *  - `all-tiendas` -> every store (kind = tienda) — admin only
 *
 * Exposes two request attributes for controllers:
 *  - `warehouse_ids` int[]    locations the request reads/aggregates over
 *  - `warehouse_id`  int|null the single concrete location (writes), null for aggregates
 */
class ResolveWarehouse
{
    public const SCOPE_ALL_WAREHOUSES = 'all';
    public const SCOPE_ALL_STORES = 'all-tiendas';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'No autenticado.', 401);
        }

        $header = trim((string) $request->header('X-Warehouse-Id', ''));

        // All stores — admin only.
        if ($header === self::SCOPE_ALL_STORES) {
            if (! $user->isAdmin()) {
                return $this->error('FORBIDDEN', 'No tienes permisos para ver las tiendas.', 403);
            }

            return $this->withScope($request, $next, $user->accessibleWarehouseIds('tienda'), null);
        }

        // All warehouses (almacenes) — aggregate view, admin only.
        if ($header === self::SCOPE_ALL_WAREHOUSES) {
            if (! $user->isAdmin()) {
                return $this->error('FORBIDDEN', 'No tienes permisos para ver todos los almacenes.', 403);
            }

            return $this->withScope($request, $next, $user->accessibleWarehouseIds('almacen'), null);
        }

        // A concrete location.
        if ($header !== '') {
            $warehouseId = (int) $header;

            if (! $user->canAccessWarehouse($warehouseId)) {
                return $this->error('FORBIDDEN', 'No tienes acceso a esta ubicación.', 403);
            }

            return $this->withScope($request, $next, [$warehouseId], $warehouseId);
        }

        // No header: admin defaults to all warehouses, almacenero to their first one.
        if ($user->isAdmin()) {
            return $this->withScope($request, $next, $user->accessibleWarehouseIds('almacen'), null);
        }

        $first = $user->accessibleWarehouseIds('almacen')[0] ?? null;

        return $this->withScope($request, $next, $first !== null ? [$first] : [], $first);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function withScope(Request $request, Closure $next, array $ids, ?int $concreteId): Response
    {
        $request->attributes->set('warehouse_ids', array_values($ids));
        $request->attributes->set('warehouse_id', $concreteId);

        return $next($request);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
