<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Secuencia global monotónica del central. Cada llamada a next() devuelve un
 * entero estrictamente creciente que se usa como cursor `sync_seq` de un
 * registro. Los nodos bajan "todo lo que tenga sync_seq mayor a mi cursor".
 */
class SyncSequence
{
    public static function next(): int
    {
        return DB::table('sync_sequence')->insertGetId(['created_at' => now()]);
    }
}
