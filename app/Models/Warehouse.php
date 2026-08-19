<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Enums\WarehouseKind;
use App\Models\Concerns\HasEntityStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasEntityStatus;
    use HasSyncIdentity;

    protected $table = 'warehouse';

    protected $fillable = [
        'name', 'kind', 'code', 'node_id', 'address', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
            'kind' => WarehouseKind::class,
        ];
    }

    public function isStore(): bool
    {
        return $this->kind === WarehouseKind::TIENDA;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_warehouse')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_warehouse')->withTimestamps();
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
