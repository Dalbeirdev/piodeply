<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProjectService;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class ProjectsIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public string $status = '';

    public ?int $clientId = null;

    public bool $showTrashed = false;

    /** Plaintext key revealed once after a rotation. */
    public ?string $revealedKey = null;

    public ?int $revealedKeyProjectId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingClientId(): void
    {
        $this->resetPage();
    }

    public function rotateKey(int $projectId, ProjectService $service): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('rotateApiKey', $project);

        $this->revealedKey = $service->rotateApiKey($project);
        $this->revealedKeyProjectId = $project->id;
    }

    public function dismissKey(): void
    {
        $this->revealedKey = null;
        $this->revealedKeyProjectId = null;
    }

    public function delete(int $projectId, ProjectService $service): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('delete', $project);

        try {
            $service->delete($project);
        } catch (\App\Exceptions\ProjectHasMachinesException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function restore(int $projectId, ProjectService $service): void
    {
        $project = Project::withTrashed()->findOrFail($projectId);
        $this->authorize('restore', $project);

        $service->restore($project);
    }

    public function render(ProjectRepositoryInterface $projects)
    {
        $this->authorize('viewAny', Project::class);

        // Tenancy: client-bound users are locked to their own client.
        $tenantId = auth()->user()->tenantClientId();

        return view('livewire.projects.projects-index', [
            'projects' => $projects->searchPaginated(
                search: $this->search,
                clientId: $tenantId ?? $this->clientId,
                status: $this->status ?: null,
                withTrashed: $tenantId === null && $this->showTrashed,
                allowedProjectIds: auth()->user()->visibleProjectIds(),
            ),
            'isTenant' => $tenantId !== null,
            'clients'  => \App\Models\Client::orderBy('company_name')->get(['id', 'company_name']),
            'statuses' => \App\Enums\ProjectStatus::cases(),
        ])->layout('layouts.app');
    }
}
