<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasEntityStatus;

    protected $table = 'product_variant';

    protected $fillable = [
        'product_id', 'sku', 'price_with_tax', 'current_stock', 'version', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
            'price_with_tax' => 'decimal:2',
            'current_stock' => 'integer',
            'version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'variant_attribute_value',
            'variant_id',
            'attribute_value_id',
        );
    }

    public function movementItems(): HasMany
    {
        return $this->hasMany(MovementItem::class, 'variant_id');
    }
}
