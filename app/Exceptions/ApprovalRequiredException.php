<?php

namespace App\Exceptions;

/**
 * Not a failure: the actor's role routes deployments through the account
 * owner, and a request has just been filed. Extends DomainException so any
 * generic handler still shows a sensible message; the main deploy paths
 * catch it specifically and present it as the success it is.
 */
class ApprovalRequiredException extends \DomainException
{
}
