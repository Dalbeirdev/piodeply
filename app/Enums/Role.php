<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'Super Admin';
    case Admin = 'Admin';
    case Manager = 'Manager';
    case Technician = 'Technician';
    case Client = 'Client';
    case Viewer = 'Viewer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
