<?php

namespace App\Livewire\Deployments;

use App\Enums\DeploymentRing;
use App\Enums\JobAction;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Services\DeploymentService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Queue one package/action across every machine in a project (optionally
 * narrowed to a deployment ring). A one-off fan-out — policies remain the
 * tool for ongoing desired state.
 */
class BulkDeploy extends Component
{
    public ?int $projectId = null;

    public ?int $packageId = null;

    public string $action = 'install';

    public int $priority = 5;

    /** Optional pinned version (winget/choco `--version`). */
    public ?string $targetVersion = null;

    /** '' = every ring, else a specific DeploymentRing value. */
    public string $ring = '';

    /** Deploy even where the machine already satisfies the request. */
    public bool $force = false;

    public function mount(): void
    {
        $this->authorize('create', DeploymentJob::class);
    }

    /** Drop an action the newly chosen package can't perform. */
    public function updatedPackageId(): void
    {
        $this->targetVersion = null;

        $package = $this->packageId !== null ? Package::active()->find($this->packageId) : null;
        $action = JobAction::tryFrom($this->action);

        if ($package !== null && $action !== null && ! $this->offersAction($package, $action)) {
            $this->action = JobAction::Install->value;
        }
    }

    public function queue(DeploymentService $service): void
    {
        $this->authorize('create', DeploymentJob::class);

        $validated = $this->validate([
            'projectId'     => ['required', 'integer', Rule::exists('projects', 'id')],
            'packageId'     => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)],
            'action'        => ['required', Rule::in(JobAction::values())],
            'priority'      => ['required', 'integer', 'between:1,10'],
            'ring'          => ['nullable', Rule::in(DeploymentRing::values())],
            'targetVersion' => ['nullable', 'string', 'max:100'],
        ]);

        $project = $this->scopedProjects()->findOrFail($validated['projectId']);
        $package = Package::findOrFail($validated['packageId']);

        $computers = Computer::where('project_id', $project->id)
            ->when($this->ring !== '', fn ($q) => $q->where('ring', $this->ring))
            ->get();

        $result = $service->queueBulk(
            computers: $computers,
            package: $package,
            action: JobAction::from($validated['action']),
            priority: $validated['priority'],
            createdBy: auth()->id(),
            targetVersion: $this->targetVersion !== null && trim($this->targetVersion) !== '' ? trim($this->targetVersion) : null,
            force: $this->force,
        );

        $this->dispatch('job-queued');
        session()->flash('status', $result->summary());
    }

    public function render()
    {
        $package = $this->packageId !== null ? Package::active()->find($this->packageId) : null;

        return view('livewire.deployments.bulk-deploy', [
            'projects'    => $this->scopedProjects()->orderBy('name')->get(['id', 'name', 'client_id']),
            'packages'    => Package::active()->orderBy('name')->get(['id', 'name', 'installer_type']),
            'rings'       => DeploymentRing::cases(),
            // Bulk covers install/update/repair/remove — rollback stays a
            // per-machine action (each machine's previous version differs).
            'actions'     => collect(JobAction::cases())
                ->reject(fn (JobAction $a) => $a === JobAction::Rollback)
                ->filter(fn (JobAction $a) => $package === null || $package->installer_type->supports($a))
                ->values()->all(),
            'versionKnown' => $package?->installer_type->requiresPackageManagerId() ?? false,
            'targetCount'  => $this->targetCount(),
        ])->layout('layouts.app');
    }

    /** Projects the current user may target (tenant users see only their own). */
    private function scopedProjects()
    {
        $tenantId = auth()->user()->tenantClientId();

        return Project::query()->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId));
    }

    private function offersAction(Package $package, JobAction $action): bool
    {
        return $action !== JobAction::Rollback && $package->installer_type->supports($action);
    }

    private function targetCount(): int
    {
        if ($this->projectId === null) {
            return 0;
        }

        $project = $this->scopedProjects()->find($this->projectId);

        if ($project === null) {
            return 0;
        }

        return Computer::where('project_id', $project->id)
            ->when($this->ring !== '', fn ($q) => $q->where('ring', $this->ring))
            ->count();
    }
}
