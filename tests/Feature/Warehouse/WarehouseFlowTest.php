<?php

namespace Tests\Feature\Warehouse;

use App\Enums\EntityStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\MovementCounterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $this->seed(MovementCounterSeeder::class);
        $admin = User::factory()->admin()->create();
        $w1 = Warehouse::query()->create(['name' => 'Principal', 'status' => EntityStatus::ACTIVE]);
        $w2 = Warehouse::query()->create(['name' => 'Secundario', 'status' => EntityStatus::ACTIVE]);
        $category = Category::query()->create(['name' => 'General', 'status' => EntityStatus::ACTIVE]);
        $unit = Unit::query()->create(['name' => 'Unidad', 'abbreviation' => 'u']);
        $product = Product::query()->create([
            'code' => 'P1', 'name' => 'Producto 1', 'category_id' => $category->id,
            'unit_id' => $unit->id, 'price' => 10, 'status' => EntityStatus::ACTIVE,
        ]);

        return [$admin, $w1, $w2, $product];
    }

    public function test_stock_is_tracked_and_aggregated_per_warehouse(): void
    {
        [$admin, $w1, $w2, $product] = $this->fixtures();

        $this->actingAs($admin, 'api')
            ->withHeaders(['X-Warehouse-Id' => (string) $w1->id])
            ->postJson('/api/v1/movements/entrada', ['items' => [['product_id' => $product->id, 'quantity' => 10]]])
            ->assertCreated()
            ->assertJsonPath('data.warehouse.id', $w1->id);

        // Scoped quantities per warehouse.
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w1->id])
            ->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.quantity', 10);
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w2->id])
            ->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.quantity', 0);

        // Transfer 4 units from w1 to w2 (leaves origin immediately, enters on reception).
        $transfer = $this->actingAs($admin, 'api')
            ->withHeaders(['X-Warehouse-Id' => (string) $w1->id])
            ->postJson('/api/v1/movements/transferencia', [
                'to_warehouse_id' => $w2->id,
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.to_warehouse.id', $w2->id)
            ->assertJsonPath('data.transfer_status', 'en_transito');
        $transferId = $transfer->json('data.id');

        // In transit: origin already down, destination not yet up.
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w1->id])
            ->getJson('/api/v1/products')->assertJsonPath('data.0.quantity', 6);
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w2->id])
            ->getJson('/api/v1/products')->assertJsonPath('data.0.quantity', 0);

        // Confirm reception at destination.
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w2->id])
            ->postJson("/api/v1/movements/{$transferId}/recibir")
            ->assertOk()
            ->assertJsonPath('data.transfer_status', 'recibido');

        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w2->id])
            ->getJson('/api/v1/products')->assertJsonPath('data.0.quantity', 4);

        // "All warehouses" aggregates the total across warehouses.
        $this->actingAs($admin, 'api')->withHeaders(['X-Warehouse-Id' => 'all'])
            ->getJson('/api/v1/products')->assertJsonPath('data.0.quantity', 10);
    }

    public function test_almacenero_cannot_reach_unassigned_or_all_warehouses(): void
    {
        [, $w1, $w2] = $this->fixtures();
        $almacenero = User::factory()->create();
        $almacenero->warehouses()->sync([$w1->id]);

        $this->actingAs($almacenero, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w1->id])
            ->getJson('/api/v1/products')->assertOk();

        $this->actingAs($almacenero, 'api')->withHeaders(['X-Warehouse-Id' => (string) $w2->id])
            ->getJson('/api/v1/products')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($almacenero, 'api')->withHeaders(['X-Warehouse-Id' => 'all'])
            ->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_movement_requires_a_concrete_warehouse_for_admin_all_scope(): void
    {
        [$admin, , , $product] = $this->fixtures();

        $this->actingAs($admin, 'api')
            ->withHeaders(['X-Warehouse-Id' => 'all'])
            ->postJson('/api/v1/movements/entrada', ['items' => [['product_id' => $product->id, 'quantity' => 5]]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WAREHOUSE_REQUIRED');
    }
}
