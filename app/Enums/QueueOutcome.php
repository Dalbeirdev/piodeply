<?php

namespace App\Enums;

/** Why DeploymentService::queueIfNeeded() did or did not create a job. */
enum QueueOutcome: string
{
    case Queued = 'queued';
    case AlreadyQueued = 'already_queued';
    case AlreadySatisfied = 'already_satisfied';

    public function queued(): bool
    {
        return $this === self::Queued;
    }
}
