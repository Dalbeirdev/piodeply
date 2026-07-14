<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PackagesView->value);
    }

    public function view(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PackagesManage->value);
    }

    public function update(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value);
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value);
    }

    public function restore(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value);
    }
}
