<?php

namespace App\Enums;

enum EntityStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case DELETED = 'deleted';
}
