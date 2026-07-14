<?php

namespace App\Enums;

enum PolicyVersionMode: string
{
    case Latest = 'latest';    // whatever the package source considers current
    case Exact = 'exact';      // exactly the desired version (up- or downgrade)
    case Minimum = 'minimum';  // at least the desired version
    case Maximum = 'maximum';  // freeze: never beyond the desired version

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Latest => 'Latest',
            self::Exact => 'Exact version',
            self::Minimum => 'Minimum version',
            self::Maximum => 'Freeze at version',
        };
    }

    public function requiresVersion(): bool
    {
        return $this !== self::Latest;
    }
}
