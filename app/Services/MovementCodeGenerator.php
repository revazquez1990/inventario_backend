<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\MovementCounter;

class MovementCodeGenerator
{
    public function next(MovementType $type): string
    {
        $counter = MovementCounter::query()->whereKey($type->value)->lockForUpdate()->firstOrFail();
        $value = $counter->next_value;
        $counter->forceFill(['next_value' => $value + 1])->save();

        // El prefijo de nodo evita que los códigos choquen al consolidarse en el central.
        $nodePrefix = config('sync.code_prefix');
        $nodePrefix = $nodePrefix !== null && $nodePrefix !== '' ? $nodePrefix.'-' : '';

        return $nodePrefix.$type->codePrefix().'-'.str_pad((string) $value, 5, '0', STR_PAD_LEFT);
    }
}
