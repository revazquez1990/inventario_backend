<?php

namespace App\Enums;

enum TransferStatus: string
{
    case EN_TRANSITO = 'en_transito';
    case RECIBIDO = 'recibido';
}
