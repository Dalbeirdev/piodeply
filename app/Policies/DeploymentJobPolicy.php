<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DeploymentJob;
use App\Models\User;

class DeploymentJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::DeploymentsView->value);
    }

    public function view(User $user, DeploymentJob $job): bool
    {
        return $user->can(Permission::DeploymentsView->value)
            && ($user->tenantClientId() === null
                || $user->tenantClientId() === $job->computer->project->client_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::DeploymentsManage->value);
    }

    public function manage(User $user, DeploymentJob $job): bool
    {
        return $user->can(Permission::DeploymentsManage->value);
    }
}
