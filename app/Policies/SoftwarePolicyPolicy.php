<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SoftwarePolicy;
use App\Models\User;

class SoftwarePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PoliciesView->value);
    }

    public function view(User $user, SoftwarePolicy $policy): bool
    {
        return $user->can(Permission::PoliciesView->value)
            && ($user->tenantClientId() === null || $user->tenantClientId() === $policy->project->client_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PoliciesManage->value);
    }

    public function update(User $user, SoftwarePolicy $policy): bool
    {
        return $user->can(Permission::PoliciesManage->value);
    }

    public function delete(User $user, SoftwarePolicy $policy): bool
    {
        return $user->can(Permission::PoliciesManage->value);
    }

    /** Enforcing queues real jobs on the fleet — manage-level. */
    public function enforce(User $user, SoftwarePolicy $policy): bool
    {
        return $user->can(Permission::PoliciesManage->value);
    }
}
