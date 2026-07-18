<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a new machine would push the account past its plan's device
 * limit. Existing machines keep working; only new enrollments are blocked.
 */
class DeviceLimitReachedException extends RuntimeException
{
    public function __construct(public int $limit, public int $current)
    {
        parent::__construct("Device limit reached ({$current}/{$limit}). Upgrade your plan to add more machines.");
    }
}
