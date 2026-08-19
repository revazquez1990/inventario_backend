<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementItem extends Model
{
    use HasSyncIdentity;

    protected $table = 'movement_item';

    public $timestamps = false;

    protected $fillable = [
        'movement_id', 'product_id', 'quantity', 'sale_price',
        'unit_price_with_tax_usd', 'unit_price_with_tax_cup',
        'subtotal_with_tax_usd', 'subtotal_tax_usd', 'subtotal_without_tax_usd',
        'subtotal_with_tax_cup', 'subtotal_tax_cup', 'subtotal_without_tax_cup',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sale_price' => 'decimal:2',
            'unit_price_with_tax_usd' => 'decimal:2',
            'unit_price_with_tax_cup' => 'decimal:2',
            'subtotal_with_tax_usd' => 'decimal:2',
            'subtotal_tax_usd' => 'decimal:2',
            'subtotal_without_tax_usd' => 'decimal:2',
            'subtotal_with_tax_cup' => 'decimal:2',
            'subtotal_tax_cup' => 'decimal:2',
            'subtotal_without_tax_cup' => 'decimal:2',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
