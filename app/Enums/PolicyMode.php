<?php

namespace App\Enums;

enum PolicyMode: string
{
    case Enforce = 'enforce';   // queue remediation jobs
    case Audit = 'audit';       // report compliance, never touch machines
    case Disabled = 'disabled'; // inert

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Enforce => 'Enforce',
            self::Audit => 'Audit only',
            self::Disabled => 'Disabled',
        };
    }
}
