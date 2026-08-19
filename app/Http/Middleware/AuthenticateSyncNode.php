<?php

namespace App\Http\Middleware;

use App\Models\SyncNode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica una laptop (nodo) contra el central mediante su token de máquina.
 * Espera las cabeceras `X-Sync-Node` (id del nodo) y `X-Sync-Token` (token en
 * claro, verificado contra el hash guardado). Es independiente del JWT de usuarios.
 */
class AuthenticateSyncNode
{
    public function handle(Request $request, Closure $next): Response
    {
        $nodeId = trim((string) $request->header('X-Sync-Node', ''));
        $token = trim((string) $request->header('X-Sync-Token', ''));

        if ($nodeId === '' || $token === '') {
            return $this->unauthorized();
        }

        $node = SyncNode::query()->where('node_id', $nodeId)->first();

        if ($node === null || ! Hash::check($token, $node->token)) {
            return $this->unauthorized();
        }

        $node->forceFill(['last_seen_at' => now()])->save();

        $request->attributes->set('sync_node', $node);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'error' => ['code' => 'SYNC_UNAUTHORIZED', 'message' => 'Nodo no autorizado.'],
        ], 401);
    }
}
