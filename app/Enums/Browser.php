<?php

namespace App\Enums;

enum Browser: string
{
    case Chrome = 'chrome';
    case Edge = 'edge';
    case Firefox = 'firefox';
    case Brave = 'brave';
    case Opera = 'opera';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Chrome => 'Google Chrome',
            self::Edge => 'Microsoft Edge',
            self::Firefox => 'Mozilla Firefox',
            self::Brave => 'Brave',
            self::Opera => 'Opera',
        };
    }
}
