<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'No autenticado.',
                ],
            ], 401);
        }

        $userRole = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if (! in_array($userRole, $roles, true)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'No tienes permisos para realizar esta acción.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
