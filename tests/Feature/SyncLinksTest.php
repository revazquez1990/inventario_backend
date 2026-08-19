<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\SyncNode;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncLinksTest extends TestCase
{
    use RefreshDatabase;

    private function headers(string $nodeId = 'GUANABACOA', string $token = 'secret-token'): array
    {
        SyncNode::create(['node_id' => $nodeId, 'name' => $nodeId, 'token' => Hash::make($token)]);

        return ['X-Sync-Node' => $nodeId, 'X-Sync-Token' => $token];
    }

    public function test_push_imports_product_attribute_links_and_stock(): void
    {
        $headers = $this->headers();
        $warehouse = Warehouse::create(['name' => 'Alm', 'kind' => 'almacen', 'code' => 'PRINCIPAL']);

        $catUuid = (string) Str::uuid();
        $unitUuid = (string) Str::uuid();
        $attrUuid = (string) Str::uuid();
        $valUuid = (string) Str::uuid();
        $prodUuid = (string) Str::uuid();
        $stockUuid = (string) Str::uuid();
        $node = 'GUANABACOA';

        $payload = ['entities' => [
            'category' => [['uuid' => $catUuid, 'origin_node_id' => $node, 'name' => 'Ropa', 'status' => 'active']],
            'unit' => [['uuid' => $unitUuid, 'origin_node_id' => $node, 'name' => 'Unidad', 'abbreviation' => 'u', 'status' => 'active']],
            'attribute' => [['uuid' => $attrUuid, 'origin_node_id' => $node, 'name' => 'Talla', 'status' => 'active']],
            'attribute_value' => [[
                'uuid' => $valUuid, 'origin_node_id' => $node, 'value' => 'M', 'status' => 'active',
                'attribute_id_uuid' => $attrUuid,
            ]],
            'product' => [[
                'uuid' => $prodUuid, 'origin_node_id' => $node,
                'code' => 'GUA-P1', 'name' => 'Camisa', 'price' => '10.00', 'status' => 'active',
                'category_id_uuid' => $catUuid, 'unit_id_uuid' => $unitUuid,
                'attribute_value_uuids' => [$valUuid],
            ]],
            'product_warehouse' => [[
                'uuid' => $stockUuid, 'origin_node_id' => $node,
                'quantity' => 7, 'sale_price' => null,
                'product_id_uuid' => $prodUuid, 'warehouse_id_uuid' => $warehouse->uuid,
            ]],
        ]];

        $this->postJson('/api/v1/sync/push', $payload, $headers)->assertOk();

        $product = Product::withoutGlobalScopes()->where('uuid', $prodUuid)->firstOrFail();
        $this->assertSame([$valUuid], $product->attributeValues()->pluck('attribute_value.uuid')->all());

        $stock = Stock::withoutGlobalScopes()->where('uuid', $stockUuid)->firstOrFail();
        $this->assertSame(7, $stock->quantity);
        $this->assertSame($product->id, $stock->product_id);
        $this->assertSame($warehouse->id, $stock->warehouse_id);
    }

    public function test_pull_includes_user_warehouse_links_and_settings(): void
    {
        $headers = $this->headers();

        $warehouse = Warehouse::create(['name' => 'Alm', 'kind' => 'almacen', 'code' => 'PRINCIPAL']);
        $user = User::create(['name' => 'Alm', 'email' => 'alm@x.com', 'password' => 'x', 'role' => 'almacenero', 'status' => 'active']);
        $user->warehouses()->sync([$warehouse->id]);

        Setting::put('tax_rate', '15.00');

        $response = $this->postJson('/api/v1/sync/pull', ['cursors' => []], $headers)->assertOk();

        $userRecord = collect($response->json('data.user.records'))->firstWhere('uuid', $user->uuid);
        $this->assertNotNull($userRecord);
        $this->assertSame([$warehouse->uuid], $userRecord['warehouse_uuids']);

        $tax = collect($response->json('data.setting'))->firstWhere('key', 'tax_rate');
        $this->assertSame('15.00', $tax['value']);
    }
}
