<?php

namespace App\Enums;

/**
 * Staged rollout: pilot machines get changes first, test after the test
 * delay, production after the production delay. Emergency machines get
 * everything immediately and ignore maintenance windows.
 */
enum DeploymentRing: string
{
    case Pilot = 'pilot';
    case Test = 'test';
    case Production = 'production';
    case Emergency = 'emergency';

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
