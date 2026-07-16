<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    /**
     * A user bound to a client acts only on that client's projects. Tenancy
     * belongs on every ability, not just the read ones: a permission answers
     * "may this user rotate keys?", never "whose keys?".
     *
     * tenantClientId() returns null for unbound staff (no restriction) and 0
     * for a Client-role account with no client set, which matches no project —
     * unbound means locked out, not waved through.
     */
    private function withinTenant(User $user, Project $project): bool
    {
        return $user->tenantClientId() === null
            || $user->tenantClientId() === $project->client_id;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsView->value)
            && $this->withinTenant($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProjectsCreate->value);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsUpdate->value)
            && $this->withinTenant($user, $project);
    }

    /** Key rotation invalidates the fleet's credentials — treat as update. */
    public function rotateApiKey(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsUpdate->value)
            && $this->withinTenant($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value)
            && $this->withinTenant($user, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value)
            && $this->withinTenant($user, $project);
    }
}
