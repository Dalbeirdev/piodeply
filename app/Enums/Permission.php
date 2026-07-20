<?php

namespace App\Enums;

/**
 * Every permission in the platform. Named `<module>.<action>`; modules map
 * to the build phases (clients, projects, computers, packages, deployments,
 * policies, schedules, reports, settings). Introduced ahead of their
 * features so the role matrix stays stable as phases land.
 */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case RolesManage = 'roles.manage';

    case ClientsView = 'clients.view';
    case ClientsCreate = 'clients.create';
    case ClientsUpdate = 'clients.update';
    case ClientsDelete = 'clients.delete';

    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';

    case ComputersView = 'computers.view';
    case ComputersManage = 'computers.manage';
    case AgentsManage = 'agents.manage';

    case PackagesView = 'packages.view';
    case PackagesManage = 'packages.manage';

    case LicensesView = 'licenses.view';
    case LicensesManage = 'licenses.manage';

    case DeploymentsView = 'deployments.view';
    case DeploymentsManage = 'deployments.manage';

    case PoliciesView = 'policies.view';
    case PoliciesManage = 'policies.manage';

    case SchedulesView = 'schedules.view';
    case SchedulesManage = 'schedules.manage';

    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    case ActivityView = 'activity.view';
    case SettingsManage = 'settings.manage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** All `<module>.view` permissions — the Viewer baseline. */
    /** @return list<string> */
    public static function viewOnly(): array
    {
        return array_values(array_filter(
            self::values(),
            fn (string $permission) => str_ends_with($permission, '.view')
        ));
    }
}
