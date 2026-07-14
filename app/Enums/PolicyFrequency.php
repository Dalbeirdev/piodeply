<?php

namespace App\Enums;

/**
 * How often recurring remediations (routine updates, force updates) may
 * re-run per machine. One-shot actions (install, remove) are unaffected —
 * they run as soon as drift is detected.
 */
enum PolicyFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Slightly under the nominal period so a fixed schedule never skips. */
    public function cooldownHours(): int
    {
        return match ($this) {
            self::Daily => 23,
            self::Weekly => 7 * 24 - 1,
            self::Monthly => 30 * 24 - 1,
        };
    }
}
