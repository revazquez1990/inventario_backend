<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasEntityStatus;
    use HasSyncIdentity;

    protected $table = 'product';

    protected $fillable = [
        'code', 'name', 'category_id', 'unit_id',
        'price', 'reference', 'image', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
            'price' => 'decimal:2',
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

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'product_warehouse')
            ->withPivot('quantity')
            ->withTimestamps();
    }
}
