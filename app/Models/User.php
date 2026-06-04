<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Enums\UserRole;
use App\Models\Concerns\HasEntityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory;
    use HasEntityStatus;

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

    public function scopeForLogin(Builder $query): Builder
    {
        return $query->whereIn('status', [EntityStatus::ACTIVE->value]);
    }
}
