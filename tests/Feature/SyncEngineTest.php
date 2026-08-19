<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MovementItem;
use App\Models\Product;
use App\Models\SyncNode;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncEngineTest extends TestCase
{
    use RefreshDatabase;

    private function authorizeNode(string $nodeId = 'GUANABACOA', string $token = 'secret-token'): array
    {
        SyncNode::create(['node_id' => $nodeId, 'name' => $nodeId, 'token' => Hash::make($token)]);

        return ['X-Sync-Node' => $nodeId, 'X-Sync-Token' => $token];
    }

    public function test_rejects_unauthorized_node(): void
    {
        $this->authorizeNode();

        $this->postJson('/api/v1/sync/pull', [], ['X-Sync-Node' => 'GUANABACOA', 'X-Sync-Token' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'SYNC_UNAUTHORIZED');
    }

    public function test_push_imports_records_resolving_relations_by_uuid(): void
    {
        $headers = $this->authorizeNode();

        // Maestros que el central ya posee y que el nodo referencia por uuid.
        $warehouse = Warehouse::create(['name' => 'Almacén Guanabacoa', 'kind' => 'almacen', 'code' => 'PRINCIPAL']);
        $user = User::create(['name' => 'Admin', 'email' => 'a@x.com', 'password' => 'x', 'role' => 'admin', 'status' => 'active']);

        $catUuid = (string) Str::uuid();
        $unitUuid = (string) Str::uuid();
        $prodUuid = (string) Str::uuid();
        $mvUuid = (string) Str::uuid();
        $miUuid = (string) Str::uuid();
        $node = 'GUANABACOA';

        $payload = ['entities' => [
            'category' => [[
                'uuid' => $catUuid, 'origin_node_id' => $node,
                'name' => 'Ropa', 'status' => 'active',
                'created_at' => '2026-07-31 10:00:00', 'updated_at' => '2026-07-31 10:00:00',
            ]],
            'unit' => [[
                'uuid' => $unitUuid, 'origin_node_id' => $node,
                'name' => 'Unidad', 'abbreviation' => 'u', 'status' => 'active',
                'created_at' => '2026-07-31 10:00:00', 'updated_at' => '2026-07-31 10:00:00',
            ]],
            'product' => [[
                'uuid' => $prodUuid, 'origin_node_id' => $node,
                'code' => 'GUA-P1', 'name' => 'Camisa', 'price' => '10.00', 'status' => 'active',
                'category_id_uuid' => $catUuid, 'unit_id_uuid' => $unitUuid,
                'created_at' => '2026-07-31 10:00:00', 'updated_at' => '2026-07-31 10:00:00',
            ]],
            'movement' => [[
                'uuid' => $mvUuid, 'origin_node_id' => $node,
                'type' => 'entrada', 'code' => 'GUA-E-00001', 'status' => 'activo',
                'exchange_rate_snapshot' => '120.0000', 'tax_rate_snapshot' => '12.00',
                'warehouse_id_uuid' => $warehouse->uuid,
                'created_by_user_id_uuid' => $user->uuid,
                'created_at' => '2026-07-31 10:00:00', 'updated_at' => '2026-07-31 10:00:00',
            ]],
            'movement_item' => [[
                'uuid' => $miUuid, 'origin_node_id' => $node,
                'quantity' => 5,
                'unit_price_with_tax_usd' => '10.00', 'unit_price_with_tax_cup' => '1200.00',
                'subtotal_with_tax_usd' => '50.00', 'subtotal_tax_usd' => '5.36', 'subtotal_without_tax_usd' => '44.64',
                'subtotal_with_tax_cup' => '6000.00', 'subtotal_tax_cup' => '642.86', 'subtotal_without_tax_cup' => '5357.14',
                'movement_id_uuid' => $mvUuid, 'product_id_uuid' => $prodUuid,
            ]],
        ]];

        $this->postJson('/api/v1/sync/push', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.acked.product', [$prodUuid])
            ->assertJsonPath('data.acked.movement', [$mvUuid]);

        // Relaciones resueltas a ids locales del central.
        $product = Product::withoutGlobalScopes()->where('uuid', $prodUuid)->firstOrFail();
        $this->assertSame(Category::where('uuid', $catUuid)->value('id'), $product->category_id);
        $this->assertSame(Unit::where('uuid', $unitUuid)->value('id'), $product->unit_id);

        $item = MovementItem::withoutGlobalScopes()->where('uuid', $miUuid)->firstOrFail();
        $this->assertSame($product->id, $item->product_id);

        // Idempotencia: reenviar el mismo lote no duplica.
        $this->postJson('/api/v1/sync/push', $payload, $headers)->assertOk();
        $this->assertSame(1, Product::withoutGlobalScopes()->where('uuid', $prodUuid)->count());
    }

    public function test_pull_returns_changes_and_excludes_requesting_node_origin(): void
    {
        $headers = $this->authorizeNode('GUANABACOA', 'secret-token');

        // Categoría propia del central (debe bajar) y otra originada por el nodo (no debe volver).
        Category::create(['name' => 'DelCentral', 'status' => 'active']);
        $own = Category::create(['name' => 'DelNodo', 'status' => 'active']);
        $own->forceFill(['origin_node_id' => 'GUANABACOA'])->save();

        $response = $this->postJson('/api/v1/sync/pull', ['cursors' => []], $headers)->assertOk();

        $names = collect($response->json('data.category.records'))->pluck('name')->all();
        $this->assertContains('DelCentral', $names);
        $this->assertNotContains('DelNodo', $names);
        $this->assertGreaterThan(0, $response->json('data.category.max_seq'));
    }
}
