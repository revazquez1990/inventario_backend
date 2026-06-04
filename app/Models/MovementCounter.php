<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;

class MovementCounter extends Model
{
    protected $table = 'movement_counter';

    protected $primaryKey = 'type';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['type', 'next_value'];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'next_value' => 'integer',
        ];
    }
}
