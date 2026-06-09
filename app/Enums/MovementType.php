<?php

namespace App\Enums;

enum MovementType: string
{
    case ENTRADA = 'entrada';
    case SALIDA = 'salida';
    case VENTA = 'venta';
    case AJUSTE = 'ajuste';
    case ANULACION = 'anulacion';
    case TRANSFERENCIA = 'transferencia';

    public function codePrefix(): string
    {
        return match ($this) {
            self::ENTRADA => 'E',
            self::SALIDA => 'S',
            self::VENTA => 'V',
            self::AJUSTE => 'A',
            self::ANULACION => 'X',
            self::TRANSFERENCIA => 'T',
        };
    }
}
