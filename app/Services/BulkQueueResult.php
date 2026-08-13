<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * The tally from a fan-out deploy: how many machines took the job, how many
 * were skipped (already satisfied or already queued), how many refused it
 * (the installer type can't perform the action), and how many became
 * approval requests instead (the actor's role routes through the owner).
 */
final readonly class BulkQueueResult
{
    public function __construct(
        public int $queued,
        public int $skipped,
        public int $refused,
        public int $total,
        public int $requested = 0,
    ) {
    }

    public function summary(): string
    {
        if ($this->total === 0) {
            return 'No machines matched that selection — nothing was queued.';
        }

        if ($this->requested > 0 && $this->queued === 0) {
            return 'Sent for approval on '.$this->requested.' '.Str::plural('machine', $this->requested)
                .' — your administrator will approve or reject it.';
        }

        $parts = ['Queued on '.$this->queued.' '.Str::plural('machine', $this->queued)];

        if ($this->requested > 0) {
            $parts[] = $this->requested.' sent for approval';
        }
        if ($this->skipped > 0) {
            $parts[] = $this->skipped.' already up to date or queued';
        }
        if ($this->refused > 0) {
            $parts[] = $this->refused.' unsupported';
        }

        return implode(' · ', $parts).'.';
    }
}
