<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\TransferStatus;
use App\Models\Category;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MovementService;
use App\Services\Sync\SyncEngine;
use Database\Seeders\MovementCounterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferReceptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $origin;
    private Warehouse $destination;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MovementCounterSeeder::class);

        $this->user = User::create(['name' => 'Admin', 'email' => 'a@x.com', 'password' => 'x', 'role' => 'admin', 'status' => 'active']);
        $this->origin = Warehouse::create(['name' => 'Guanabacoa', 'kind' => 'almacen', 'code' => 'GUA', 'node_id' => 'GUANABACOA']);
        $this->destination = Warehouse::create(['name' => 'Alamar', 'kind' => 'almacen', 'code' => 'ALA', 'node_id' => 'ALAMAR']);

        $category = Category::create(['name' => 'Ropa', 'status' => 'active']);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'status' => 'active']);
        $this->product = Product::create([
            'code' => 'P1', 'name' => 'Camisa', 'category_id' => $category->id, 'unit_id' => $unit->id, 'price' => 10, 'status' => 'active',
        ]);

        Stock::create(['product_id' => $this->product->id, 'warehouse_id' => $this->origin->id, 'quantity' => 100]);
    }

    private function transfer(int $qty = 10): Movement
    {
        return app(MovementService::class)->create(
            MovementType::TRANSFERENCIA,
            ['items' => [['product_id' => $this->product->id, 'quantity' => $qty]]],
            $this->user,
            $this->origin->id,
            $this->destination->id,
        );
    }

    private function qty(Warehouse $w): int
    {
        return (int) (Stock::where('product_id', $this->product->id)->where('warehouse_id', $w->id)->value('quantity') ?? 0);
    }

    public function test_transfer_only_leaves_origin_until_received(): void
    {
        $movement = $this->transfer(10);

        $this->assertSame(90, $this->qty($this->origin));
        $this->assertSame(0, $this->qty($this->destination)); // aún no entra
        $this->assertSame(TransferStatus::EN_TRANSITO, $movement->transfer_status);

        app(MovementService::class)->confirmReception($movement, $this->user);

        $this->assertSame(90, $this->qty($this->origin));
        $this->assertSame(10, $this->qty($this->destination)); // entra al recibir
        $this->assertSame(TransferStatus::RECIBIDO, $movement->refresh()->transfer_status);
    }

    public function test_cannot_receive_twice(): void
    {
        $movement = $this->transfer(5);
        app(MovementService::class)->confirmReception($movement, $this->user);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MovementService::class)->confirmReception($movement, $this->user);
    }

    public function test_void_in_transit_restores_origin_only(): void
    {
        $movement = $this->transfer(10);
        app(MovementService::class)->void($movement, $this->user, 'error de captura');

        $this->assertSame(100, $this->qty($this->origin)); // devuelto
        $this->assertSame(0, $this->qty($this->destination)); // nunca recibió
    }

    public function test_void_after_received_restores_both(): void
    {
        $movement = $this->transfer(10);
        app(MovementService::class)->confirmReception($movement, $this->user);
        app(MovementService::class)->void($movement, $this->user, 'devolución completa');

        $this->assertSame(100, $this->qty($this->origin));
        $this->assertSame(0, $this->qty($this->destination));
    }

    public function test_incoming_transfers_are_routed_to_destination_node(): void
    {
        $movement = $this->transfer(7);
        // Simula que el movimiento se originó en el nodo de Guanabacoa.
        $movement->forceFill(['origin_node_id' => 'GUANABACOA'])->save();

        $incoming = app(SyncEngine::class)->incomingTransfersFor('ALAMAR');

        $this->assertCount(1, $incoming['movement']);
        $this->assertSame($movement->uuid, $incoming['movement'][0]['uuid']);
        $this->assertCount(1, $incoming['movement_item']);

        // Y no aparece para un nodo que no opera el destino.
        $this->assertCount(0, app(SyncEngine::class)->incomingTransfersFor('OTRO')['movement']);
    }
}
