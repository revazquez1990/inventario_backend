<?php

namespace App\Models\Concerns;

use App\Enums\EntityStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a global scope that hides rows where `status = 'deleted'` and
 * provides query helpers to include or exclusively target deleted rows.
 */
trait HasEntityStatus
{
    public static function bootHasEntityStatus(): void
    {
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->where($builder->getModel()->getTable().'.status', '!=', EntityStatus::DELETED->value);
        });
    }

    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted')
            ->where($query->getModel()->getTable().'.status', EntityStatus::DELETED->value);
    }

    public function scopeOnlyActive(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', EntityStatus::ACTIVE->value);
    }

    public function softDeleteStatus(): bool
    {
        $this->forceFill(['status' => EntityStatus::DELETED->value]);

        return $this->save();
    }
}
