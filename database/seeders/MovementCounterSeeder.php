<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Models\MovementCounter;
use Illuminate\Database\Seeder;

class MovementCounterSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MovementType::cases() as $type) {
            MovementCounter::query()->updateOrCreate(
                ['type' => $type->value],
                ['next_value' => 1],
            );
        }
    }
}
