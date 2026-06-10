<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthController extends Controller
{
    private const ACCESS_TTL_MINUTES = 60;
    private const REFRESH_TTL_MINUTES = 10080;

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()
            ->where('email', $credentials['email'])
            ->where('status', EntityStatus::ACTIVE)
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $this->authError('INVALID_CREDENTIALS', 'Credenciales inválidas.');
        }

        return response()->json($this->tokenPairFor($user));
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $payload = JWTAuth::setToken($request->validated('refresh_token'))->getPayload();
        } catch (JWTException) {
            return $this->authError('INVALID_REFRESH_TOKEN', 'Refresh token inválido o vencido.');
        }

        if ($payload->get('typ') !== 'refresh') {
            return $this->authError('INVALID_REFRESH_TOKEN', 'Refresh token inválido.');
        }

        $user = User::query()
            ->whereKey($payload->get('sub'))
            ->where('status', EntityStatus::ACTIVE)
            ->first();

        if (! $user) {
            return $this->authError('UNAUTHENTICATED', 'Usuario no autorizado.');
        }

        try {
            JWTAuth::setToken($request->validated('refresh_token'))->invalidate();
        } catch (JWTException) {
            return $this->authError('INVALID_REFRESH_TOKEN', 'Refresh token inválido o vencido.');
        }

        JWTAuth::unsetToken();

        return response()->json($this->tokenPairFor($user));
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            JWTAuth::unsetToken();
            JWTAuth::setRequest($request)->parseToken()->invalidate();
            JWTAuth::unsetToken();
        } catch (JWTException) {
            throw new UnauthorizedHttpException('Bearer', 'Token inválido o vencido.');
        }

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->serializeUser($request->user()),
        ]);
    }

    /**
     * @return array{token: string, refresh_token: string, user: array<string, mixed>}
     */
    private function tokenPairFor(User $user): array
    {
        JWTAuth::factory()->setTTL(self::ACCESS_TTL_MINUTES);
        $token = JWTAuth::claims(['typ' => 'access'])->fromUser($user);

        JWTAuth::factory()->setTTL(self::REFRESH_TTL_MINUTES);
        $refreshToken = JWTAuth::claims(['typ' => 'refresh'])->fromUser($user);

        return [
            'token' => $token,
            'refresh_token' => $refreshToken,
            'user' => $this->serializeUser($user),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, status: string}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'warehouses' => Warehouse::query()
                ->whereIn('id', $user->accessibleWarehouseIds())
                ->orderBy('name')
                ->get(['id', 'name', 'kind']),
        ];
    }

    private function authError(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 401);
    }
}
