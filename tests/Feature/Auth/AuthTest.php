<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Enums\EntityStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_and_read_profile(): void
    {
        User::factory()->admin()->create([
            'name' => 'Administrador',
            'email' => 'admin@inventario.local',
            'password' => 'admin123',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@inventario.local',
            'password' => 'admin123',
        ]);

        $login->assertOk()
            ->assertJsonPath('user.email', 'admin@inventario.local')
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonStructure(['token', 'refresh_token', 'user' => ['id', 'name', 'email', 'role', 'status']]);

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@inventario.local');
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'almacenero@inventario.local',
            'password' => 'almacen123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'almacenero@inventario.local',
            'password' => 'incorrecta',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_refresh_rotates_tokens_and_logout_invalidates_access_token(): void
    {
        User::factory()->create([
            'email' => 'almacenero@inventario.local',
            'password' => 'almacen123',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'almacenero@inventario.local',
            'password' => 'almacen123',
        ])->assertOk();

        $refresh = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login->json('refresh_token'),
        ])->assertOk()
            ->assertJsonStructure(['token', 'refresh_token', 'user']);

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login->json('refresh_token'),
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_REFRESH_TOKEN');

        $this->withToken($refresh->json('token'))
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();

        $this->withToken($refresh->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_inactive_user_with_existing_token_is_blocked(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $user->forceFill(['status' => EntityStatus::INACTIVE])->save();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'USER_INACTIVE');
    }
}
