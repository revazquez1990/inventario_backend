<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            WarehouseSeeder::class,
            UserSeeder::class,
            SettingSeeder::class,
            UnitSeeder::class,
            AttributeSeeder::class,
            SupplierSeeder::class,
            MovementCounterSeeder::class,
        ]);
    }
}
