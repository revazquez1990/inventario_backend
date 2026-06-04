<?php

namespace Database\Seeders;

use App\Enums\EntityStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@inventario.local'],
            [
                'name' => 'Administrador',
                'password' => 'admin123',
                'role' => UserRole::ADMIN,
                'status' => EntityStatus::ACTIVE,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'almacenero@inventario.local'],
            [
                'name' => 'Almacenero Demo',
                'password' => 'almacen123',
                'role' => UserRole::ALMACENERO,
                'status' => EntityStatus::ACTIVE,
            ],
        );
    }
}
