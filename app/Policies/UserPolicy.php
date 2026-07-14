<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Pattern-setter for entity policies: abilities delegate to Spatie
 * permissions; Super Admin bypasses via Gate::before.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersCreate->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::UsersUpdate->value);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can(Permission::UsersDelete->value) && ! $user->is($model);
    }

    /** Assigning/removing roles is stricter than a profile update. */
    public function assignRole(User $user, User $model): bool
    {
        return $user->can(Permission::RolesManage->value) && ! $user->is($model);
    }
}
