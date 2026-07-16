<?php

namespace App\Services;

use App\Enums\QueueOutcome;
use App\Models\DeploymentJob;

/**
 * The result of asking for a deployment. A skipped request is a success,
 * not an error — there was simply nothing to do.
 */
final readonly class QueueResult
{
    public function __construct(
        public QueueOutcome $outcome,
        public ?DeploymentJob $job,
        public string $message,
    ) {
    }

    public function queued(): bool
    {
        return $this->outcome->queued();
    }
}
