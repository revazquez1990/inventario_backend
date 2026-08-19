<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeValue extends Model
{
    use HasEntityStatus;
    use HasSyncIdentity;

    protected $table = 'attribute_value';

    protected $fillable = ['attribute_id', 'value', 'status'];

    protected function casts(): array
    {
        return [
            'status' => EntityStatus::class,
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
