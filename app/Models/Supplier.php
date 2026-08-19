<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasEntityStatus;
    use HasSyncIdentity;

    protected $table = 'supplier';

    protected $fillable = [
        'name', 'contact_name', 'phone', 'email', 'address', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }
}
