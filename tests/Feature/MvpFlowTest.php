<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\MovementCounterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MvpFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_import_template_and_stock_movement_flow_work(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seed(MovementCounterSeeder::class);
        $supplier = Supplier::query()->create(['name' => 'Inventario Inicial']);
        $unit = Unit::query()->create(['name' => 'Unidad', 'abbreviation' => 'u']);

        $categoryId = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/categories', ['name' => 'Ropa'])
            ->assertCreated()
            ->json('data.id');

        $variantId = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/products', [
                'name' => 'Camisa Polo',
                'sku_base' => 'CAMISA001',
                'category_id' => $categoryId,
                'unit_id' => $unit->id,
                'min_stock' => 5,
                'variants' => [
                    ['sku' => 'CAMISA001-M', 'price_with_tax' => 12.50],
                ],
            ])
            ->assertCreated()
            ->json('data.variants.0.id');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/products/import-template?format=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/exchange-rate', ['usd_to_cup' => 320])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/movements/entrada', [
                'supplier_id' => $supplier->id,
                'items' => [['variant_id' => $variantId, 'quantity' => 10, 'unit_price_with_tax_usd' => 8.00]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'E-00001');

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/movements/venta', [
                'items' => [['variant_id' => $variantId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'V-00001');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.current_stock', 8);
    }

    public function test_import_preview_and_confirm_create_initial_stock_through_entry(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seed(MovementCounterSeeder::class);

        $csv = "category,unit,product_name,sku_base,description,min_stock,variant_sku,price_with_tax_usd,attributes,initial_stock,purchase_price_usd\n".
            "Accesorios,u,Gorra Demo,GORRA001,Gorra importada,3,GORRA001-NEGRA,9.75,Color=Negro,6,6.50\n";
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($admin, 'api')
            ->post('/api/v1/products/import/preview', ['file' => new UploadedFile($path, 'productos.csv', 'text/csv', null, true)])
            ->assertOk()
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.errors_count', 0);

        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($admin, 'api')
            ->post('/api/v1/products/import', ['file' => new UploadedFile($path, 'productos.csv', 'text/csv', null, true)])
            ->assertOk()
            ->assertJsonPath('data.created_products', 1)
            ->assertJsonPath('data.initial_stock_lines', 1);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/products?search=GORRA001')
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.current_stock', 6);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/movements?type=entrada')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
