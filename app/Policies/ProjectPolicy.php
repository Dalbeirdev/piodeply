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

    public function view(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProjectsCreate->value);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    /** Key rotation invalidates the fleet's credentials — treat as update. */
    public function rotateApiKey(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value);
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value);
    }
}
