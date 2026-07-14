<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\User;

/**
 * Builds the main navigation for a user based on their permissions.
 * Items for future phases are added here as their routes land; anything
 * whose route does not exist yet is filtered out automatically, so the
 * menu definition can stay ahead of the build.
 */
class NavigationService
{
    /**
     * @return list<array{label: string, route: string, active: string}>
     */
    public function items(User $user): array
    {
        $definition = [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => null],
            ['label' => 'Users', 'route' => 'admin.users', 'active' => 'admin.users*', 'permission' => Permission::UsersView],
            // Future phases (routes appear as they are built):
            ['label' => 'Clients', 'route' => 'clients.index', 'active' => 'clients.*', 'permission' => Permission::ClientsView],
            ['label' => 'Projects', 'route' => 'projects.index', 'active' => 'projects.*', 'permission' => Permission::ProjectsView],
            ['label' => 'Computers', 'route' => 'computers.index', 'active' => 'computers.*', 'permission' => Permission::ComputersView],
            ['label' => 'Packages', 'route' => 'packages.index', 'active' => 'packages.*', 'permission' => Permission::PackagesView],
            ['label' => 'Deployments', 'route' => 'deployments.index', 'active' => 'deployments.*', 'permission' => Permission::DeploymentsView],
            ['label' => 'Reports', 'route' => 'reports.index', 'active' => 'reports.*', 'permission' => Permission::ReportsView],
        ];

        return collect($definition)
            ->filter(fn (array $item) => \Illuminate\Support\Facades\Route::has($item['route']))
            ->filter(fn (array $item) => $item['permission'] === null || $user->can($item['permission']->value))
            ->map(fn (array $item) => [
                'label'  => $item['label'],
                'route'  => $item['route'],
                'active' => $item['active'],
            ])
            ->values()
            ->all();
    }
}
