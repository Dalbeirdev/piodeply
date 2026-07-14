<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Permission;
use App\Http\Resources\DeploymentJobResource;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Services\DeploymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeploymentsController extends IntegrationController
{
    public function index(Request $request)
    {
        $this->requireAbility($request, 'read', Permission::DeploymentsView->value);

        return DeploymentJobResource::collection(
            DeploymentJob::query()
                ->with(['computer', 'package'])
                ->when($this->tenantId($request) !== null, fn ($q) => $q->whereHas(
                    'computer.project',
                    fn ($p) => $p->withTrashed()->where('client_id', $this->tenantId($request))
                ))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('computer_id'), fn ($q) => $q->where('computer_id', $request->integer('computer_id')))
                ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
                ->orderByDesc('id')
                ->paginate(min(100, (int) $request->integer('per_page', 25)))
        );
    }

    /** Queue a deployment job — the API twin of the portal's quick deploy. */
    public function store(Request $request, DeploymentService $service)
    {
        $this->requireAbility($request, 'deploy', Permission::DeploymentsManage->value);

        $validated = $request->validate([
            'computer_id' => ['required', 'integer', Rule::exists('computers', 'id')->whereNull('deleted_at')],
            'package_id'  => ['required', 'integer', Rule::exists('packages', 'id')->whereNull('deleted_at')],
            'action'      => ['required', Rule::in(JobAction::values())],
            'priority'    => ['sometimes', 'integer', 'between:1,10'],
            'target_version' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $computer = Computer::findOrFail($validated['computer_id']);
        abort_unless($request->user()->can('view', $computer), 404); // tenancy

        $job = $service->queue(
            computer: $computer,
            package: Package::findOrFail($validated['package_id']),
            action: JobAction::from($validated['action']),
            priority: $validated['priority'] ?? 5,
            createdBy: $request->user()->id,
            targetVersion: $validated['target_version'] ?? null,
        );

        return (new DeploymentJobResource($job->load(['computer', 'package'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, DeploymentJob $job)
    {
        $this->requireAbility($request, 'read', Permission::DeploymentsView->value);
        abort_unless($request->user()->can('view', $job), 404);

        return new DeploymentJobResource($job->load(['computer', 'package']));
    }
}
