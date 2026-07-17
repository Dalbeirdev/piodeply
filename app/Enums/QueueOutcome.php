<?php

namespace App\Enums;

/** Why DeploymentService::queueIfNeeded() did or did not create a job. */
enum QueueOutcome: string
{
    case Queued = 'queued';
    case AlreadyQueued = 'already_queued';
    case AlreadySatisfied = 'already_satisfied';

    /** The request cannot be carried out as asked — not "nothing to do". */
    case Invalid = 'invalid';

    public function queued(): bool
    {
        return $this === self::Queued;
    }
}
