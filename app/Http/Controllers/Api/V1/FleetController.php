<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ComputerResource;
use App\Http\Resources\PackageResource;
use App\Http\Resources\ProjectResource;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Read endpoints: clients, projects, computers, packages. All lists are
 * paginated and tenancy-scoped.
 */
class FleetController extends IntegrationController
{
    public function clients(Request $request)
    {
        $this->requireAbility($request, 'read', Permission::ClientsView->value);

        return ClientResource::collection(
            Client::query()
                ->when($this->tenantId($request) !== null, fn ($q) => $q->whereKey($this->tenantId($request)))
                ->orderBy('company_name')
                ->paginate(min(100, (int) $request->integer('per_page', 25)))
        );
    }

    public function projects(Request $request)
    {
        $this->requireAbility($request, 'read', Permission::ProjectsView->value);

        return ProjectResource::collection(
            Project::query()
                ->withCount('computers')
                ->when($this->tenantId($request) !== null, fn ($q) => $q->where('client_id', $this->tenantId($request)))
                ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
                ->orderBy('name')
                ->paginate(min(100, (int) $request->integer('per_page', 25)))
        );
    }

    public function computers(Request $request)
    {
        $this->requireAbility($request, 'read', Permission::ComputersView->value);

        return ComputerResource::collection(
            Computer::query()
                ->when($this->tenantId($request) !== null, fn ($q) => $q->whereHas(
                    'project',
                    fn ($p) => $p->withTrashed()->where('client_id', $this->tenantId($request))
                ))
                ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
                ->when($request->filled('ring'), fn ($q) => $q->where('ring', $request->string('ring')))
                ->when($request->boolean('online'), fn ($q) => $q->online())
                ->when($request->filled('search'), fn ($q) => $q->search($request->string('search')))
                ->orderBy('hostname')
                ->paginate(min(100, (int) $request->integer('per_page', 25)))
        );
    }

    public function computer(Request $request, Computer $computer)
    {
        $this->requireAbility($request, 'read', Permission::ComputersView->value);
        abort_unless($request->user()->can('view', $computer), 404); // tenancy: don't reveal existence

        return new ComputerResource(
            $request->boolean('with_software') ? $computer->load('software') : $computer
        );
    }

    public function packages(Request $request)
    {
        $this->requireAbility($request, 'read', Permission::PackagesView->value);

        return PackageResource::collection(
            Package::query()
                ->with('latestVersion')
                ->when($request->boolean('active_only', true), fn ($q) => $q->active())
                ->when($request->filled('search'), fn ($q) => $q->search($request->string('search')))
                ->orderBy('name')
                ->paginate(min(100, (int) $request->integer('per_page', 25)))
        );
    }
}
