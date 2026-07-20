<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Package;
use App\Models\User;

/**
 * Two audiences, one catalogue: staff curate the shared catalogue and may
 * see everything; a tenant works with the catalogue plus packages private
 * to their own client — creating, editing and deleting only their own.
 * Staff may view a client's private package (support needs eyes) but the
 * deploy-side guard in DeploymentService keeps even staff from ever using
 * it for another client.
 */
class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PackagesView->value);
    }

    public function view(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesView->value)
            && ($package->client_id === null
                || $user->tenantClientId() === null
                || $user->tenantClientId() === $package->client_id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PackagesManage->value);
    }

    public function update(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value) && $this->owns($user, $package);
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value) && $this->owns($user, $package);
    }

    public function restore(User $user, Package $package): bool
    {
        return $user->can(Permission::PackagesManage->value) && $this->owns($user, $package);
    }

    /**
     * Staff own the catalogue and, for support, may edit private packages
     * too. A tenant owns exactly their client's packages — never the
     * shared catalogue, never another tenant's.
     */
    private function owns(User $user, Package $package): bool
    {
        return $user->tenantClientId() === null
            || $user->tenantClientId() === $package->client_id;
    }
}
