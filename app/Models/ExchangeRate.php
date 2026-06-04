<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $table = 'exchange_rate';

    protected $fillable = ['rate_date', 'usd_to_cup', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'usd_to_cup' => 'decimal:4',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
