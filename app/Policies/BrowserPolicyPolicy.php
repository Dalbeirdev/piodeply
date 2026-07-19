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
        if (! $user->can(Permission::PoliciesView->value)) {
            return false;
        }

        $tenantId = $user->tenantClientId();

        // Tenants may open a policy when it can affect their machines —
        // the same rule the list uses, so a listed row is always openable.
        return $tenantId === null
            || BrowserPolicy::visibleTo($tenantId)->whereKey($policy->id)->exists();
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
