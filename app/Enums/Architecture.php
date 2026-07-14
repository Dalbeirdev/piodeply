<?php

namespace App\Enums;

enum Architecture: string
{
    case X64 = 'x64';
    case X86 = 'x86';
    case Arm64 = 'arm64';
    case Any = 'any';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
