<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'tax_rate' => '12.00',
            'business_name' => 'Mi Negocio',
            'business_address' => '',
            'business_phone' => '',
        ];

        foreach ($defaults as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }
}
