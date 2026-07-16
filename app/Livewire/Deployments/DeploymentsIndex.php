<?php

namespace App\Livewire\Deployments;

use App\Models\DeploymentJob;
use App\Services\DeploymentService;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class DeploymentsIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public string $status = '';

    public string $action = '';

    /**
     * Off (the default) shows the current job per computer+package+action,
     * so a package deployed ten times is one row, not ten. On shows every
     * attempt — the full audit trail is never deleted, only folded.
     */
    public bool $history = false;

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'status', 'action', 'history'], true)) {
            $this->resetPage();
        }
    }

    public function retry(int $jobId, DeploymentService $service): void
    {
        $job = DeploymentJob::findOrFail($jobId);
        $this->authorize('manage', $job);
        $service->retry($job);
    }

    public function cancel(int $jobId, DeploymentService $service): void
    {
        $job = DeploymentJob::findOrFail($jobId);
        $this->authorize('manage', $job);
        $service->cancel($job);
    }

    public function render()
    {
        $this->authorize('viewAny', DeploymentJob::class);

        // Tenancy: client-bound users see only their own machines' jobs.
        $tenantId = auth()->user()->tenantClientId();

        // "Same task" = same computer, package and action. Keeping only the
        // newest id per group collapses repeats; the count tells the
        // operator how many times it was asked for.
        $sameTask = 'x.computer_id = deployment_jobs.computer_id
                     and x.package_id = deployment_jobs.package_id
                     and x.action = deployment_jobs.action';

        $jobs = DeploymentJob::query()
            ->with(['computer', 'package', 'packageVersion'])
            ->select('deployment_jobs.*')
            ->selectRaw("(select count(*) from deployment_jobs x where {$sameTask}) as repeat_count")
            ->unless($this->history, fn ($q) => $q->whereRaw(
                "deployment_jobs.id = (select max(x.id) from deployment_jobs x where {$sameTask})"
            ))
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'computer.project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
            ))
            ->when($this->search !== '', fn ($q) => $q->whereHas('computer', fn ($c) => $c->where('hostname', 'like', "%{$this->search}%"))
                ->orWhereHas('package', fn ($p) => $p->where('name', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.deployments.deployments-index', [
            'jobs'     => $jobs,
            'statuses' => \App\Enums\JobStatus::cases(),
            'actions'  => \App\Enums\JobAction::cases(),
        ])->layout('layouts.app');
    }
}
