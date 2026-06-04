<?php

namespace App\Http\Middleware;

use App\Enums\EntityStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->status !== EntityStatus::ACTIVE) {
            return response()->json([
                'error' => [
                    'code' => 'USER_INACTIVE',
                    'message' => 'El usuario no está activo.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
