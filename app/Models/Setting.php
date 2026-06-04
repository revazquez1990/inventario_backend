<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $table = 'setting';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value', 'updated_by_user_id', 'updated_at'];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, string $value, ?int $userId = null): self
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $userId, 'updated_at' => now()],
        );
    }
}
