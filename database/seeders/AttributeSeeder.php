<?php

namespace Database\Seeders;

use App\Models\Attribute;
use Illuminate\Database\Seeder;

class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $attributes = [
            'Talla' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'Color' => ['Rojo', 'Azul', 'Verde', 'Negro', 'Blanco', 'Gris', 'Amarillo'],
            'Sabor' => ['Vainilla', 'Chocolate', 'Fresa', 'Limón', 'Natural'],
            'Presentación' => ['Individual', 'Pack 6', 'Pack 12', 'Caja 24'],
        ];

        foreach ($attributes as $name => $values) {
            $attribute = Attribute::query()->updateOrCreate(
                ['name' => $name],
                ['name' => $name],
            );

            foreach ($values as $value) {
                $attribute->values()->updateOrCreate(
                    ['value' => $value],
                    ['value' => $value],
                );
            }
        }
    }
}
