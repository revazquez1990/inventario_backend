<?php

namespace Database\Seeders;

use App\Enums\EntityStatus;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        Warehouse::query()->updateOrCreate(
            ['code' => 'PRINCIPAL'],
            ['name' => 'Almacén Principal', 'status' => EntityStatus::ACTIVE],
        );

        Warehouse::query()->updateOrCreate(
            ['code' => 'SECUNDARIO'],
            ['name' => 'Almacén Secundario', 'status' => EntityStatus::ACTIVE],
        );
    }
}
