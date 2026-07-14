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

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'status', 'action'], true)) {
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

        $jobs = DeploymentJob::query()
            ->with(['computer', 'package', 'packageVersion'])
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
