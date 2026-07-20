<?php

namespace App\Exceptions;

/**
 * Raised when a project deletion is attempted while machines — active or
 * retired — still belong to it. Thrown from the service so the rule binds
 * every caller, including admins: a project with machines cannot vanish
 * from under its fleet.
 */
class ProjectHasMachinesException extends \DomainException
{
    public function __construct(public readonly int $active, public readonly int $retired)
    {
        $machines = $active + $retired;

        parent::__construct(
            "This project still has {$machines} machine".($machines === 1 ? '' : 's')
            ." ({$active} active, {$retired} retired). Uninstall their agents and permanently"
            .' delete the machines first — then the project can be deleted.'
        );
    }
}
