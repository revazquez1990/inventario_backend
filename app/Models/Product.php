<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasEntityStatus;

    protected $table = 'product';

    protected $fillable = [
        'code', 'name', 'category_id', 'unit_id',
        'price', 'reference', 'quantity', 'image', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
            'price' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'product_attribute_value',
            'product_id',
            'attribute_value_id',
        );
    }
}
