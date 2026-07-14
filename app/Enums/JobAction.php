<?php

namespace App\Enums;

enum JobAction: string
{
    case Install = 'install';
    case Update = 'update';
    case Repair = 'repair';
    case Uninstall = 'uninstall';
    case Rollback = 'rollback';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
