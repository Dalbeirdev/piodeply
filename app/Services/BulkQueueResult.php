<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * The tally from a fan-out deploy: how many machines took the job, how many
 * were skipped (already satisfied or already queued), and how many refused it
 * (the installer type can't perform the action).
 */
final readonly class BulkQueueResult
{
    public function __construct(
        public int $queued,
        public int $skipped,
        public int $refused,
        public int $total,
    ) {
    }

    public function summary(): string
    {
        if ($this->total === 0) {
            return 'No machines matched that selection — nothing was queued.';
        }

        $parts = ['Queued on '.$this->queued.' '.Str::plural('machine', $this->queued)];

        if ($this->skipped > 0) {
            $parts[] = $this->skipped.' already up to date or queued';
        }
        if ($this->refused > 0) {
            $parts[] = $this->refused.' unsupported';
        }

        return implode(' · ', $parts).'.';
    }
}
