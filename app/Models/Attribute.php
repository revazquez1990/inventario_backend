<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use HasEntityStatus;
    use HasSyncIdentity;

    protected $table = 'attribute';

    protected $fillable = ['name', 'status'];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
