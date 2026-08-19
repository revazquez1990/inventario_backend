<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasEntityStatus;
    use HasSyncIdentity;

    protected $table = 'unit';

    protected $fillable = ['name', 'abbreviation', 'status'];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
