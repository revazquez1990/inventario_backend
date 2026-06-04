<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::query()->updateOrCreate(
            ['name' => 'Inventario Inicial'],
            [
                'name' => 'Inventario Inicial',
                'notes' => 'Proveedor de sistema para registrar el stock inicial al cargar productos por primera vez.',
            ],
        );
    }
}
