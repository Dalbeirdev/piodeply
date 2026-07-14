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
            ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => null,
                'icon' => '<path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>'],
            ['label' => 'Users', 'route' => 'admin.users', 'active' => 'admin.users*', 'permission' => Permission::UsersView,
                'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>'],
            ['label' => 'Roles', 'route' => 'admin.roles', 'active' => 'admin.roles*', 'permission' => Permission::RolesManage,
                'icon' => '<circle cx="12" cy="8" r="3.5"/><path d="M6 21v-1.5a6 6 0 0 1 9-5.2"/><path d="m16.5 20 1.6 1 2.9-4"/>'],
            ['label' => 'Clients', 'route' => 'clients.index', 'active' => 'clients.*', 'permission' => Permission::ClientsView,
                'icon' => '<path d="M3 21h18M5 21V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v14M9 9h1M9 13h1M14 9h1M14 13h1M10 21v-4h4v4"/>'],
            ['label' => 'Projects', 'route' => 'projects.index', 'active' => 'projects.*', 'permission' => Permission::ProjectsView,
                'icon' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'],
            ['label' => 'Computers', 'route' => 'computers.index', 'active' => 'computers.*', 'permission' => Permission::ComputersView,
                'icon' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>'],
            ['label' => 'Packages', 'route' => 'packages.index', 'active' => 'packages.*', 'permission' => Permission::PackagesView,
                'icon' => '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>'],
            ['label' => 'Deployments', 'route' => 'deployments.index', 'active' => 'deployments.*', 'permission' => Permission::DeploymentsView,
                'icon' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>'],
            ['label' => 'Policies', 'route' => 'policies.index', 'active' => 'policies.*', 'permission' => Permission::PoliciesView,
                'icon' => '<path d="M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9z"/><path d="m9 12 2 2 4-4"/>'],
            ['label' => 'Browser Policies', 'route' => 'browser-policies.index', 'active' => 'browser-policies.*', 'permission' => Permission::PoliciesView,
                'icon' => '<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>'],
            ['label' => 'Reports', 'route' => 'reports.index', 'active' => 'reports.*', 'permission' => Permission::ReportsView,
                'icon' => '<path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-6"/>'],
            ['label' => 'Notifications', 'route' => 'admin.notifications', 'active' => 'admin.notifications*', 'permission' => Permission::SettingsManage,
                'icon' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'],
            ['label' => 'Activity', 'route' => 'activity.index', 'active' => 'activity.*', 'permission' => Permission::ActivityView,
                'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
            ['label' => 'Settings', 'route' => 'admin.settings', 'active' => 'admin.settings*', 'permission' => Permission::SettingsManage,
                'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
        ];

        return collect($definition)
            ->filter(fn (array $item) => \Illuminate\Support\Facades\Route::has($item['route']))
            ->filter(fn (array $item) => $item['permission'] === null || $user->can($item['permission']->value))
            ->map(fn (array $item) => [
                'label'  => $item['label'],
                'route'  => $item['route'],
                'active' => $item['active'],
                'icon'   => $item['icon'] ?? '',
            ])
            ->values()
            ->all();
    }
}
