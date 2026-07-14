<?php

namespace App\Livewire\Admin;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Role–permission matrix: every checkbox saves instantly. Super Admin is
 * not editable — Gate::before grants it everything regardless of what is
 * stored, so showing checkboxes for it would be a lie.
 */
class ManageRoles extends Component
{
    public function toggle(string $roleName, string $permission): void
    {
        $this->authorizeManage();

        abort_if($roleName === RoleEnum::SuperAdmin->value, 403, 'Super Admin always has full access.');
        abort_unless(in_array($roleName, RoleEnum::values(), true), 422);
        abort_unless(in_array($permission, PermissionEnum::values(), true), 422);

        $role = Role::findByName($roleName, 'web');
        $has = $role->hasPermissionTo($permission);

        // Lockout guard: revoking roles.manage from a role you hold would
        // cut off your own access to this page.
        if ($has
            && $permission === PermissionEnum::RolesManage->value
            && auth()->user()->hasRole($roleName)) {
            abort(403, 'You cannot remove "Manage roles" from your own role — you would lock yourself out.');
        }

        $has ? $role->revokePermissionTo($permission) : $role->givePermissionTo($permission);

        activity('rbac')
            ->causedBy(auth()->user())
            ->withProperties(['role' => $roleName, 'permission' => $permission, 'granted' => ! $has])
            ->log($has ? 'permission_revoked' : 'permission_granted');
    }

    public function resetDefaults(): void
    {
        $this->authorizeManage();

        app(RolesAndPermissionsSeeder::class)->run();

        activity('rbac')->causedBy(auth()->user())->log('permissions_reset_to_defaults');
        session()->flash('status', 'All roles reset to the platform default permissions.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(PermissionEnum::RolesManage->value), 403);
    }

    public function render()
    {
        $this->authorizeManage();

        $editableRoles = collect(RoleEnum::cases())
            ->reject(fn (RoleEnum $role) => $role === RoleEnum::SuperAdmin)
            ->map(fn (RoleEnum $role) => $role->value)
            ->values();

        $granted = Role::with('permissions')
            ->whereIn('name', $editableRoles)
            ->get()
            ->mapWithKeys(fn (Role $role) => [
                $role->name => $role->permissions->pluck('name')->flip()->map(fn () => true)->all(),
            ]);

        // Rows grouped by module ("users.view" → module "users"), with a
        // readable label per permission ("View users").
        $modules = collect(PermissionEnum::cases())
            ->groupBy(fn (PermissionEnum $permission) => explode('.', $permission->value)[0])
            ->map(fn ($group) => $group->map(fn (PermissionEnum $permission) => [
                'value' => $permission->value,
                'label' => $this->labelFor($permission),
            ])->values());

        return view('livewire.admin.manage-roles', [
            'roles'   => $editableRoles,
            'granted' => $granted,
            'modules' => $modules,
        ])->layout('layouts.app');
    }

    private function labelFor(PermissionEnum $permission): string
    {
        [$module, $action] = explode('.', $permission->value);

        return ucfirst($action) . ' ' . str_replace('_', ' ', $module);
    }
}
