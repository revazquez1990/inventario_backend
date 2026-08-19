<?php

namespace App\Services;

use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Enums\TransferStatus;
use App\Models\ExchangeRate;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MovementService
{
    public function __construct(
        private readonly MovementCodeGenerator $codeGenerator,
        private readonly StockService $stockService,
    ) {
    }

    public function create(MovementType $type, array $data, User $user, int $warehouseId, ?int $toWarehouseId = null): Movement
    {
        return DB::transaction(function () use ($type, $data, $user, $warehouseId, $toWarehouseId) {
            $rate = ExchangeRate::query()->where('rate_date', now('America/Bogota')->toDateString())->orderByDesc('created_at')->first()
                ?? ExchangeRate::query()->orderByDesc('rate_date')->orderByDesc('created_at')->first();
            $exchange = $rate ? (float) $rate->usd_to_cup : 1.0;
            $taxRate = (float) Setting::get('tax_rate', '0.00');
            $totals = ['without_usd' => 0.0, 'tax_usd' => 0.0, 'with_usd' => 0.0, 'without_cup' => 0.0, 'tax_cup' => 0.0, 'with_cup' => 0.0];
            $items = [];

            foreach ($data['items'] as $line) {
                $quantity = (int) $line['quantity'];
                $productId = (int) $line['product_id'];
                $salePrice = null;

                if ($type === MovementType::TRANSFERENCIA) {
                    // Fase 1: la mercancía solo sale del origen. Entra al destino al
                    // confirmarse la recepción (transfer_status: en_transito -> recibido).
                    $this->stockService->lockAndApply($productId, $warehouseId, -abs($quantity));
                    $salePrice = isset($line['sale_price']) && $line['sale_price'] !== null
                        ? (float) $line['sale_price']
                        : null;
                } else {
                    $this->stockService->lockAndApply($productId, $warehouseId, $this->stockDelta($type, $quantity));
                }

                $product = Product::query()->whereKey($productId)->firstOrFail();
                $price = (float) ($line['unit_price_with_tax_usd'] ?? $product->price);
                $calc = $this->calculateLine($price, abs($quantity), $taxRate, $exchange);

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $calc[$key];
                }

                $items[] = ['product_id' => $product->id, 'quantity' => abs($quantity), 'sale_price' => $salePrice, 'calc' => $calc];
            }

            $movement = Movement::query()->create([
                'type' => $type,
                'adjustment_subtype' => $data['adjustment_subtype'] ?? null,
                'code' => $this->codeGenerator->next($type),
                'transfer_status' => $type === MovementType::TRANSFERENCIA ? TransferStatus::EN_TRANSITO : null,
                'warehouse_id' => $warehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'exchange_rate_snapshot' => $exchange,
                'exchange_rate_id' => $rate?->id,
                'tax_rate_snapshot' => $taxRate,
                'supplier_id' => $data['supplier_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'created_by_user_id' => $user->id,
                'total_without_tax_usd' => $totals['without_usd'],
                'total_tax_usd' => $totals['tax_usd'],
                'total_with_tax_usd' => $totals['with_usd'],
                'total_without_tax_cup' => $totals['without_cup'],
                'total_tax_cup' => $totals['tax_cup'],
                'total_with_tax_cup' => $totals['with_cup'],
            ]);

            foreach ($items as $item) {
                $movement->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'sale_price' => $item['sale_price'],
                    'unit_price_with_tax_usd' => $item['calc']['unit_usd'],
                    'unit_price_with_tax_cup' => $item['calc']['unit_cup'],
                    'subtotal_with_tax_usd' => $item['calc']['with_usd'],
                    'subtotal_tax_usd' => $item['calc']['tax_usd'],
                    'subtotal_without_tax_usd' => $item['calc']['without_usd'],
                    'subtotal_with_tax_cup' => $item['calc']['with_cup'],
                    'subtotal_tax_cup' => $item['calc']['tax_cup'],
                    'subtotal_without_tax_cup' => $item['calc']['without_cup'],
                ]);
            }

            return $movement;
        });
    }

    public function void(Movement $movement, User $user, string $reason): Movement
    {
        return DB::transaction(function () use ($movement, $user, $reason) {
            $original = Movement::query()->with('items')->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if ($original->status === MovementStatus::ANULADO || $original->type === MovementType::ANULACION) {
                throw new HttpException(409, 'Este movimiento no se puede anular.');
            }

            foreach ($original->items as $item) {
                if ($original->type === MovementType::TRANSFERENCIA) {
                    // Siempre se devuelve al origen; solo se descuenta del destino si ya se había recibido.
                    $this->stockService->lockAndApply($item->product_id, $original->warehouse_id, abs($item->quantity));
                    if ($original->transfer_status === TransferStatus::RECIBIDO) {
                        $this->stockService->lockAndApply($item->product_id, (int) $original->to_warehouse_id, -abs($item->quantity));
                    }
                } else {
                    $this->stockService->lockAndApply($item->product_id, $original->warehouse_id, -$this->stockDelta($original->type, $item->quantity));
                }
            }

            $original->forceFill([
                'status' => MovementStatus::ANULADO,
                'voided_by_user_id' => $user->id,
                'voided_at' => now(),
                'reason_void' => $reason,
            ])->save();

            $void = Movement::query()->create([
                'type' => MovementType::ANULACION,
                'code' => $this->codeGenerator->next(MovementType::ANULACION),
                'warehouse_id' => $original->warehouse_id,
                'to_warehouse_id' => $original->to_warehouse_id,
                'exchange_rate_snapshot' => $original->exchange_rate_snapshot,
                'exchange_rate_id' => $original->exchange_rate_id,
                'tax_rate_snapshot' => $original->tax_rate_snapshot,
                'original_movement_id' => $original->id,
                'reason' => $reason,
                'created_by_user_id' => $user->id,
                'total_without_tax_usd' => $original->total_without_tax_usd,
                'total_tax_usd' => $original->total_tax_usd,
                'total_with_tax_usd' => $original->total_with_tax_usd,
                'total_without_tax_cup' => $original->total_without_tax_cup,
                'total_tax_cup' => $original->total_tax_cup,
                'total_with_tax_cup' => $original->total_with_tax_cup,
            ]);

            foreach ($original->items as $item) {
                $void->items()->create($item->only([
                    'product_id', 'quantity', 'unit_price_with_tax_usd', 'unit_price_with_tax_cup',
                    'subtotal_with_tax_usd', 'subtotal_tax_usd', 'subtotal_without_tax_usd',
                    'subtotal_with_tax_cup', 'subtotal_tax_cup', 'subtotal_without_tax_cup',
                ]));
            }

            return $void;
        });
    }

    /**
     * Confirma la recepción de una transferencia en tránsito: suma el stock en el
     * almacén destino (con el precio de venta previsto si es tienda) y la marca
     * como recibida. En un nodo, encola la actualización para subirla al central
     * aunque la transferencia se haya originado en otro nodo.
     */
    public function confirmReception(Movement $movement, User $user): Movement
    {
        return DB::transaction(function () use ($movement, $user) {
            $transfer = Movement::query()->with('items')->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if ($transfer->type !== MovementType::TRANSFERENCIA) {
                throw new HttpException(422, 'El movimiento no es una transferencia.');
            }

            if ($transfer->status === MovementStatus::ANULADO) {
                throw new HttpException(409, 'La transferencia está anulada.');
            }

            if ($transfer->transfer_status === TransferStatus::RECIBIDO) {
                throw new HttpException(409, 'La transferencia ya fue recibida.');
            }

            $destinationIsStore = Warehouse::query()->find($transfer->to_warehouse_id)?->isStore() === true;

            foreach ($transfer->items as $item) {
                $salePrice = $destinationIsStore && $item->sale_price !== null ? (float) $item->sale_price : null;
                $this->stockService->lockAndApply($item->product_id, (int) $transfer->to_warehouse_id, abs($item->quantity), $salePrice);
            }

            $transfer->forceFill([
                'transfer_status' => TransferStatus::RECIBIDO,
                'received_by_user_id' => $user->id,
                'received_at' => now(),
            ])->save();

            // La transferencia pudo originarse en otro nodo, por lo que el outbox
            // (que solo encola origen local) no la incluiría: se encola explícitamente.
            if (config('sync.role') === 'node') {
                SyncOutbox::query()->updateOrInsert(
                    ['entity_type' => 'movement', 'entity_uuid' => $transfer->uuid],
                    ['queued_at' => now()],
                );
            }

            return $transfer;
        });
    }

    public function stockDelta(MovementType $type, int $quantity): int
    {
        return match ($type) {
            MovementType::ENTRADA => abs($quantity),
            MovementType::SALIDA, MovementType::VENTA => -abs($quantity),
            MovementType::AJUSTE => $quantity,
            MovementType::ANULACION, MovementType::TRANSFERENCIA => 0,
        };
    }

    public function calculateLine(float $price, int $quantity, float $taxRate, float $exchange): array
    {
        $withUsd = round($price * $quantity, 2);
        $withoutUsd = round($withUsd / (1 + ($taxRate / 100)), 2);
        $taxUsd = round($withUsd - $withoutUsd, 2);
        $withCup = round($withUsd * $exchange, 2);
        $withoutCup = round($withoutUsd * $exchange, 2);

        return [
            'unit_usd' => round($price, 2),
            'unit_cup' => round($price * $exchange, 2),
            'without_usd' => $withoutUsd,
            'tax_usd' => $taxUsd,
            'with_usd' => $withUsd,
            'without_cup' => $withoutCup,
            'tax_cup' => round($withCup - $withoutCup, 2),
            'with_cup' => $withCup,
        ];
    }
}
