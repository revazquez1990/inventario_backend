<?php

namespace App\Services;

use App\Models\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockService
{
    public function lockAndApply(int $productId, int $delta): Product
    {
        $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

        if ($product->quantity + $delta < 0) {
            throw new HttpException(409, "Stock insuficiente para {$product->name}.");
        }

        $product->forceFill(['quantity' => $product->quantity + $delta])->save();

        return $product;
    }
}
