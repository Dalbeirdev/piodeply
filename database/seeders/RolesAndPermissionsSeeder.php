<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Idempotent: permissions and roles are upserted, assignments synced.
     * Super Admin holds no explicit permissions — Gate::before in
     * AppServiceProvider grants everything.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $matrix = [
            RoleEnum::SuperAdmin->value => [], // full access via Gate::before

            RoleEnum::Admin->value => PermissionEnum::values(),

            RoleEnum::Manager->value => $managerPermissions = [
                PermissionEnum::UsersView,
                PermissionEnum::ClientsView, PermissionEnum::ClientsCreate, PermissionEnum::ClientsUpdate,
                PermissionEnum::ProjectsView, PermissionEnum::ProjectsCreate, PermissionEnum::ProjectsUpdate, PermissionEnum::ProjectsDelete,
                PermissionEnum::ComputersView, PermissionEnum::ComputersManage,
                PermissionEnum::PackagesView,
                PermissionEnum::DeploymentsView, PermissionEnum::DeploymentsManage,
                PermissionEnum::PoliciesView, PermissionEnum::PoliciesManage,
                PermissionEnum::SchedulesView, PermissionEnum::SchedulesManage,
                PermissionEnum::ReportsView, PermissionEnum::ReportsExport,
                PermissionEnum::ActivityView,
            ],

            // A customer's owner account. Identical capabilities to Manager
            // ON PURPOSE — the tenancy binding (client_id on the user) is
            // what limits everything to their own company, and that layer is
            // already enforced and tested. A separate role exists so the
            // Users list reads honestly: staff are Managers, customers are
            // Client Owners.
            RoleEnum::ClientOwner->value => $managerPermissions,

            RoleEnum::Technician->value => [
                PermissionEnum::ClientsView,
                PermissionEnum::ProjectsView,
                PermissionEnum::ComputersView, PermissionEnum::ComputersManage,
                PermissionEnum::PackagesView,
                PermissionEnum::DeploymentsView, PermissionEnum::DeploymentsManage,
                PermissionEnum::SchedulesView,
                PermissionEnum::ReportsView,
            ],

            RoleEnum::Client->value => [
                PermissionEnum::ProjectsView,
                PermissionEnum::ComputersView,
                PermissionEnum::DeploymentsView,
                PermissionEnum::ReportsView,
            ],

            // View-only across modules — except the user directory, which
            // stays reserved for Manager+ (least privilege).
            RoleEnum::Viewer->value => array_values(array_diff(
                PermissionEnum::viewOnly(),
                [PermissionEnum::UsersView->value]
            )),
        ];

        foreach ($matrix as $role => $permissions) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(array_map(
                    fn ($permission) => $permission instanceof PermissionEnum ? $permission->value : $permission,
                    $permissions
                ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
