<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Models\Movement;
use App\Services\MovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MovementController extends Controller
{
    public function __construct(private readonly MovementService $movementService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Movement::query()
            ->with(['createdBy:id,name,email,role,status', 'supplier', 'items.product'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('created_by_user_id', $request->integer('user_id')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('code'), fn ($q) => $q->where('code', 'like', '%'.$request->string('code')->toString().'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $movements = $query->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => $movements->getCollection()->map(fn (Movement $movement) => $this->serializeMovement($movement))->values(),
            'meta' => [
                'total' => $movements->total(),
                'page' => $movements->currentPage(),
                'per_page' => $movements->perPage(),
                'last_page' => $movements->lastPage(),
            ],
        ]);
    }

    public function show(Movement $movement): JsonResponse
    {
        return response()->json(['data' => $this->serializeMovement($movement->load(['createdBy', 'voidedBy', 'supplier', 'items.product']))]);
    }

    public function entrada(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('supplier', 'id')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('product', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_with_tax_usd' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->createMovementResponse($request, MovementType::ENTRADA, $data);
    }

    public function salida(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('product', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->createMovementResponse($request, MovementType::SALIDA, $data);
    }

    public function venta(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('product', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->createMovementResponse($request, MovementType::VENTA, $data);
    }

    public function ajuste(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'adjustment_subtype' => ['required', Rule::in(['merma', 'rotura', 'conteo_fisico'])],
            'reason' => ['required', 'string', 'min:5'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('product', 'id')],
            'items.*.quantity' => ['required', 'integer', 'not_in:0'],
        ]);

        return $this->createMovementResponse($request, MovementType::AJUSTE, $data);
    }

    public function anular(Request $request, Movement $movement): JsonResponse
    {
        $data = $request->validate(['reason_void' => ['required', 'string', 'min:5']]);

        $voidMovement = $this->movementService->void($movement, $request->user(), $data['reason_void']);

        return response()->json(['data' => $this->serializeMovement($voidMovement->load(['items.product', 'createdBy']))], 201);
    }

    private function createMovementResponse(Request $request, MovementType $type, array $data): JsonResponse
    {
        $movement = $this->movementService->create($type, $data, $request->user());

        return response()->json(['data' => $this->serializeMovement($movement->load(['items.product', 'createdBy', 'supplier']))], 201);
    }

    private function serializeMovement(Movement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type?->value ?? (string) $movement->getRawOriginal('type'),
            'adjustment_subtype' => $movement->adjustment_subtype?->value,
            'code' => $movement->code,
            'status' => $movement->status?->value ?? MovementStatus::ACTIVO->value,
            'exchange_rate_snapshot' => $movement->exchange_rate_snapshot,
            'tax_rate_snapshot' => $movement->tax_rate_snapshot,
            'reason' => $movement->reason,
            'reason_void' => $movement->reason_void,
            'supplier' => $movement->supplier ? ['id' => $movement->supplier->id, 'name' => $movement->supplier->name] : null,
            'created_by' => $movement->createdBy ? ['id' => $movement->createdBy->id, 'name' => $movement->createdBy->name, 'email' => $movement->createdBy->email] : null,
            'voided_by' => $movement->voidedBy ? ['id' => $movement->voidedBy->id, 'name' => $movement->voidedBy->name, 'email' => $movement->voidedBy->email] : null,
            'voided_at' => $movement->voided_at?->toDateTimeString(),
            'created_at' => $movement->created_at?->toDateTimeString(),
            'totals' => [
                'without_tax_usd' => $movement->total_without_tax_usd,
                'tax_usd' => $movement->total_tax_usd,
                'with_tax_usd' => $movement->total_with_tax_usd,
                'without_tax_cup' => $movement->total_without_tax_cup,
                'tax_cup' => $movement->total_tax_cup,
                'with_tax_cup' => $movement->total_with_tax_cup,
            ],
            'items' => $movement->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'code' => $item->product?->code,
                'product_name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unit_price_with_tax_usd' => $item->unit_price_with_tax_usd,
                'subtotal_with_tax_usd' => $item->subtotal_with_tax_usd,
                'subtotal_with_tax_cup' => $item->subtotal_with_tax_cup,
            ])->values(),
        ];
    }
}
