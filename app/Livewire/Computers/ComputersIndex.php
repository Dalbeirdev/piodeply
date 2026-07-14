<?php

namespace App\Livewire\Computers;

use App\Models\Computer;
use App\Repositories\Contracts\ComputerRepositoryInterface;
use App\Services\ComputerService;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class ComputersIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public ?int $clientId = null;

    public ?int $projectId = null;

    public string $connectivity = ''; // '', 'online', 'offline'

    public bool $showTrashed = false;

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'clientId', 'projectId', 'connectivity', 'showTrashed'], true)) {
            $this->resetPage();
        }
    }

    public function delete(int $computerId, ComputerService $service): void
    {
        $computer = Computer::findOrFail($computerId);
        $this->authorize('delete', $computer);

        $service->delete($computer);
    }

    public function restore(int $computerId, ComputerService $service): void
    {
        $computer = Computer::withTrashed()->findOrFail($computerId);
        $this->authorize('restore', $computer);

        $service->restore($computer);
    }

    public function render(ComputerRepositoryInterface $computers)
    {
        $this->authorize('viewAny', Computer::class);

        // Tenancy: client-bound users are locked to their own client.
        $tenantId = auth()->user()->tenantClientId();

        return view('livewire.computers.computers-index', [
            'computers' => $computers->searchPaginated(
                search: $this->search,
                projectId: $this->projectId,
                clientId: $tenantId ?? $this->clientId,
                online: $this->connectivity === '' ? null : $this->connectivity === 'online',
                withTrashed: $tenantId === null && $this->showTrashed,
            ),
            'clients'  => $tenantId === null
                ? \App\Models\Client::orderBy('company_name')->get(['id', 'company_name'])
                : collect(),
            'projects' => \App\Models\Project::when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->orderBy('name')->get(['id', 'name', 'client_id']),
            'isTenant' => $tenantId !== null,
        ])->layout('layouts.app');
    }
}
