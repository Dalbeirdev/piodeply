<?php

namespace App\Enums;

enum JobStatus: string
{
    case Pending = 'pending';       // queued, ready to be claimed
    case Blocked = 'blocked';       // waiting on a dependency job
    case Running = 'running';       // claimed by an agent
    case Succeeded = 'succeeded';
    case Failed = 'failed';         // exhausted retries
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }
}
