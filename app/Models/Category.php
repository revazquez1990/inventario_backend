<?php

namespace App\Models;

use App\Enums\EntityStatus;
use App\Models\Concerns\HasEntityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasEntityStatus;

    protected $table = 'category';

    protected $fillable = ['name', 'description', 'status'];

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
