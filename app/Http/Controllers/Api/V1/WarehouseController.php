<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntityStatus;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    /**
     * Warehouses accessible to the current user (admin: all; almacenero: assigned).
     * Feeds the warehouse selector.
     */
    public function index(Request $request): JsonResponse
    {
        $accessibleIds = $request->user()->accessibleWarehouseIds();

        $warehouses = Warehouse::query()
            ->whereIn('id', $accessibleIds)
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
     * @return array<string, mixed>
     */
    private function validateWarehouse(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:160'],
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
            'code' => $warehouse->code,
            'address' => $warehouse->address,
            'status' => $warehouse->status?->value ?? EntityStatus::ACTIVE->value,
        ];
    }
}
