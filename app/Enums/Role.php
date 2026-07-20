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

    /**
     * A paying customer's own account: everything a Manager can do, but the
     * tenancy binding limits it all to their company. Separate from Manager
     * purely so the Users list reads honestly — staff are Managers,
     * customers are Client Owners.
     */
    case ClientOwner = 'Client Owner';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
