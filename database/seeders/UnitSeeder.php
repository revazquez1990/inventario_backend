<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['name' => 'Unidad', 'abbreviation' => 'u'],
            ['name' => 'Litros', 'abbreviation' => 'L'],
            ['name' => 'Metro cuadrado', 'abbreviation' => 'm²'],
            ['name' => 'Metro', 'abbreviation' => 'm'],
            ['name' => 'Kit', 'abbreviation' => 'kit'],
        ];

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(
                ['abbreviation' => $unit['abbreviation']],
                $unit,
            );
        }
    }
}
