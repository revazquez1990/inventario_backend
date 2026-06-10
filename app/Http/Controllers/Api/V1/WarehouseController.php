<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntityStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    /**
     * Locations accessible to the current user (admin: all; almacenero: assigned
     * almacenes — stores are transparent to them). Optional `kind` filter.
     * Feeds the location selector and the Almacenes/Tiendas admin lists.
     */
    public function index(Request $request): JsonResponse
    {
        $accessibleIds = $request->user()->accessibleWarehouseIds();

        $warehouses = Warehouse::query()
            ->whereIn('id', $accessibleIds)
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->toString()))
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse) => $this->serialize($warehouse))
            ->values();

        return response()->json(['data' => $warehouses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateWarehouse($request);
        $data['status'] ??= EntityStatus::ACTIVE->value;
        $data['kind'] ??= 'almacen';

        $warehouse = Warehouse::query()->create($data);

        return response()->json(['data' => $this->serialize($warehouse)], 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        return response()->json(['data' => $this->serialize($warehouse)]);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $this->validateWarehouse($request, $warehouse->id);

        $warehouse->fill($data)->save();

        return response()->json(['data' => $this->serialize($warehouse->refresh())]);
    }

    public function delete(Warehouse $warehouse): JsonResponse
    {
        $warehouse->softDeleteStatus();

        return response()->json(null, 204);
    }

    /**
     * Products held in this location with their stock and (for stores) sale price.
     */
    public function products(Warehouse $warehouse): JsonResponse
    {
        $rows = Stock::query()
            ->where('warehouse_id', $warehouse->id)
            ->with('product:id,code,name,price')
            ->get()
            ->filter(fn (Stock $stock) => $stock->product !== null)
            ->map(fn (Stock $stock) => [
                'product_id' => $stock->product_id,
                'code' => $stock->product->code,
                'name' => $stock->product->name,
                'base_price' => $stock->product->price,
                'quantity' => (int) $stock->quantity,
                'sale_price' => $stock->sale_price !== null ? (string) $stock->sale_price : null,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Set the sale price of a product in this (store) location.
     */
    public function updateProductPrice(Request $request, Warehouse $warehouse, Product $product): JsonResponse
    {
        $data = $request->validate([
            'sale_price' => ['required', 'numeric', 'min:0'],
        ]);

        $stock = Stock::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity' => 0],
        );
        $stock->forceFill(['sale_price' => $data['sale_price']])->save();

        return response()->json(['data' => [
            'product_id' => $product->id,
            'sale_price' => (string) $stock->sale_price,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWarehouse(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:160'],
            'kind' => ['sometimes', Rule::in(['almacen', 'tienda'])],
            'code' => ['nullable', 'string', 'max:60', Rule::unique('warehouse', 'code')->ignore($ignoreId)],
            'address' => ['nullable', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'kind' => $warehouse->kind?->value ?? 'almacen',
            'code' => $warehouse->code,
            'address' => $warehouse->address,
            'status' => $warehouse->status?->value ?? EntityStatus::ACTIVE->value,
        ];
    }
}
