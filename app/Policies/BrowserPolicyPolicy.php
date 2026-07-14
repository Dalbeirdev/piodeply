<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BrowserPolicy;
use App\Models\User;

class BrowserPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PoliciesView->value);
    }

    public function view(User $user, BrowserPolicy $policy): bool
    {
        return $user->can(Permission::PoliciesView->value)
            && ($user->tenantClientId() === null || $user->tenantClientId() === $policy->project->client_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PoliciesManage->value);
    }

    public function update(User $user, BrowserPolicy $policy): bool
    {
        return $user->can(Permission::PoliciesManage->value);
    }

    public function delete(User $user, BrowserPolicy $policy): bool
    {
        return $user->can(Permission::PoliciesManage->value);
    }
}
