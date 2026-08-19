<?php

namespace App\Models;

use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasSyncIdentity;

    protected $table = 'product_warehouse';

    protected $fillable = [
        'product_id', 'warehouse_id', 'quantity', 'sale_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sale_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
