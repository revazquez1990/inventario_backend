<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\MovementCounter;
use App\Services\MovementCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_records_get_uuid_and_origin_node(): void
    {
        config(['sync.node_id' => 'GUANABACOA']);

        $category = Category::create(['name' => 'Ropa']);

        $this->assertNotNull($category->uuid);
        $this->assertSame(36, strlen($category->uuid));
        $this->assertSame('GUANABACOA', $category->origin_node_id);
    }

    public function test_movement_code_is_prefixed_by_node(): void
    {
        config(['sync.code_prefix' => 'GUA']);
        MovementCounter::create(['type' => MovementType::ENTRADA, 'next_value' => 1]);

        $code = app(MovementCodeGenerator::class)->next(MovementType::ENTRADA);

        $this->assertSame('GUA-E-00001', $code);
    }

    public function test_movement_code_has_no_prefix_when_unset(): void
    {
        config(['sync.code_prefix' => '']);
        MovementCounter::create(['type' => MovementType::SALIDA, 'next_value' => 7]);

        $code = app(MovementCodeGenerator::class)->next(MovementType::SALIDA);

        $this->assertSame('S-00007', $code);
    }
}
