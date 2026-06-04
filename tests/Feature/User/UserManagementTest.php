<?php

namespace Tests\Feature\User;

use App\Enums\EntityStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_update_and_delete_user_logically(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/users', [
                'name' => 'Almacenero Norte',
                'email' => 'norte@inventario.local',
                'password' => 'almacen123',
                'role' => 'almacenero',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'norte@inventario.local')
            ->assertJsonPath('data.status', 'active');

        $userId = $created->json('data.id');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/users?search=norte')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'norte@inventario.local');

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/users/{$userId}", [
                'name' => 'Almacenero Principal',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Almacenero Principal')
            ->assertJsonPath('data.status', 'inactive');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/users/{$userId}/status", [
                'status' => 'deleted',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/users?status=deleted')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'norte@inventario.local');
    }

    public function test_almacenero_cannot_manage_users(): void
    {
        $almacenero = User::factory()->create();

        $this->actingAs($almacenero, 'api')
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_deleted_users_are_hidden_from_default_list(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['status' => EntityStatus::DELETED]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', $admin->email);
    }
}
