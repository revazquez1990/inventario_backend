<?php

namespace Database\Seeders;

use App\Enums\EntityStatus;
use App\Enums\WarehouseKind;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        Warehouse::query()->updateOrCreate(
            ['code' => 'PRINCIPAL'],
            ['name' => 'Almacén Principal', 'kind' => WarehouseKind::ALMACEN, 'status' => EntityStatus::ACTIVE],
        );

        Warehouse::query()->updateOrCreate(
            ['code' => 'SECUNDARIO'],
            ['name' => 'Almacén Secundario', 'kind' => WarehouseKind::ALMACEN, 'status' => EntityStatus::ACTIVE],
        );

        Warehouse::query()->updateOrCreate(
            ['code' => 'TIENDA-CENTRO'],
            ['name' => 'Tienda Centro', 'kind' => WarehouseKind::TIENDA, 'status' => EntityStatus::ACTIVE],
        );
    }
}
