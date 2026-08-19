<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Enums\UserRole;
use App\Models\Concerns\HasEntityStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory;
    use HasEntityStatus;
    use HasSyncIdentity;

    protected $table = 'user';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => EntityStatus::class,
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role instanceof UserRole ? $this->role->value : (string) $this->role,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouse')->withTimestamps();
    }

    /**
     * IDs of locations this user may access. Admins implicitly reach every
     * active warehouse and store; almaceneros only the warehouses (almacenes)
     * explicitly assigned — stores are always transparent to them.
     *
     * @param  string|null  $kind  Optional filter: 'almacen' | 'tienda'.
     * @return array<int, int>
     */
    public function accessibleWarehouseIds(?string $kind = null): array
    {
        if ($this->isAdmin()) {
            return Warehouse::query()->onlyActive()
                ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
                ->pluck('id')->all();
        }

        // Almaceneros never see stores, regardless of the requested kind.
        return $this->warehouses()
            ->where('warehouse.kind', 'almacen')
            ->pluck('warehouse.id')->all();
    }

    public function canAccessWarehouse(int $warehouseId): bool
    {
        return in_array($warehouseId, $this->accessibleWarehouseIds(), true);
    }

    public function scopeForLogin(Builder $query): Builder
    {
        return $query->whereIn('status', [EntityStatus::ACTIVE->value]);
    }
}
