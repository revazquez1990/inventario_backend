<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockService
{
    /**
     * Apply a stock delta to a product within a specific warehouse, locking the
     * row to keep concurrent movements consistent.
     */
    public function lockAndApply(int $productId, int $warehouseId, int $delta, ?float $salePrice = null): Stock
    {
        $stock = Stock::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            // Lock the product row so two requests cannot create the same pivot.
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $stock = Stock::query()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'quantity' => 0,
            ]);
        }

        if ($stock->quantity + $delta < 0) {
            $product = $stock->product ?? Product::query()->whereKey($productId)->first();
            throw new HttpException(409, "Stock insuficiente para {$product?->name}.");
        }

        $stock->forceFill(['quantity' => $stock->quantity + $delta]);
        if ($salePrice !== null) {
            $stock->forceFill(['sale_price' => $salePrice]);
        }
        $stock->save();

        return $stock;
    }
}
