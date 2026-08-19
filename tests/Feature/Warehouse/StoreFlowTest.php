<?php

namespace Tests\Feature\Warehouse;

use App\Enums\EntityStatus;
use App\Enums\WarehouseKind;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\MovementCounterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $this->seed(MovementCounterSeeder::class);
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::query()->create(['name' => 'Principal', 'kind' => WarehouseKind::ALMACEN, 'status' => EntityStatus::ACTIVE]);
        $store = Warehouse::query()->create(['name' => 'Tienda Centro', 'kind' => WarehouseKind::TIENDA, 'status' => EntityStatus::ACTIVE]);
        $category = Category::query()->create(['name' => 'General', 'status' => EntityStatus::ACTIVE]);
        $unit = Unit::query()->create(['name' => 'Unidad', 'abbreviation' => 'u']);
        $product = Product::query()->create([
            'code' => 'P1', 'name' => 'Producto 1', 'category_id' => $category->id,
            'unit_id' => $unit->id, 'price' => 10, 'status' => EntityStatus::ACTIVE,
        ]);

        return [$admin, $warehouse, $store, $product];
    }

    public function test_transfer_to_store_sets_sale_price_and_moves_stock(): void
    {
        [$admin, $warehouse, $store, $product] = $this->fixtures();

        // Seed stock in the warehouse, then transfer to the store with a sale price.
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $warehouse->id])
            ->postJson('/api/v1/movements/entrada', ['items' => [['product_id' => $product->id, 'quantity' => 20]]])
            ->assertCreated();

        $transfer = $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $warehouse->id])
            ->postJson('/api/v1/movements/transferencia', [
                'to_warehouse_id' => $store->id,
                'items' => [['product_id' => $product->id, 'quantity' => 7, 'sale_price' => 19.50]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.to_warehouse.kind', 'tienda');

        // Confirm reception at the store: stock and sale price land on reception.
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/movements/{$transfer->json('data.id')}/recibir")
            ->assertOk();

        // Store product list reflects stock + sale price.
        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/warehouses/{$store->id}/products")
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 7)
            ->assertJsonPath('data.0.sale_price', '19.50');

        // Products list scoped to the store exposes its sale price.
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $store->id])
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 7)
            ->assertJsonPath('data.0.sale_price', '19.50');
    }

    public function test_admin_can_edit_store_product_price(): void
    {
        [$admin, , $store, $product] = $this->fixtures();

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/warehouses/{$store->id}/products/{$product->id}/price", ['sale_price' => 25])
            ->assertOk()
            ->assertJsonPath('data.sale_price', '25.00');
    }

    public function test_stores_are_transparent_to_almaceneros(): void
    {
        [, $warehouse, $store] = $this->fixtures();
        $almacenero = User::factory()->create();
        $almacenero->warehouses()->sync([$warehouse->id]);

        // Selector list excludes stores; me() warehouses excludes stores.
        $this->actingAs($almacenero, 'api')->withHeaders(['X-Warehouse-Id' => (string) $warehouse->id])
            ->getJson('/api/v1/warehouses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $warehouse->id);

        // Direct access to a store id is forbidden.
        $this->actingAs($almacenero, 'api')->withHeaders(['X-Warehouse-Id' => (string) $store->id])
            ->getJson('/api/v1/products')->assertForbidden();

        // The "all stores" aggregate is admin only.
        $this->actingAs($almacenero, 'api')->withHeaders(['X-Warehouse-Id' => 'all-tiendas'])
            ->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_only_admin_manages_stores(): void
    {
        $almacenero = User::factory()->create();

        $this->actingAs($almacenero, 'api')
            ->postJson('/api/v1/warehouses', ['name' => 'Tienda X', 'kind' => 'tienda'])
            ->assertForbidden();
    }
}
