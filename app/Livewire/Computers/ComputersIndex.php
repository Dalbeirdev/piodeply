<?php

namespace App\Livewire\Computers;

use App\Models\Computer;
use App\Repositories\Contracts\ComputerRepositoryInterface;
use App\Services\ComputerService;
use Livewire\Component;
use Livewire\WithPagination;

class ComputersIndex extends Component
{
    use WithPagination;

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

        return view('livewire.computers.computers-index', [
            'computers' => $computers->searchPaginated(
                search: $this->search,
                projectId: $this->projectId,
                clientId: $this->clientId,
                online: $this->connectivity === '' ? null : $this->connectivity === 'online',
                withTrashed: $this->showTrashed,
            ),
            'clients'  => \App\Models\Client::orderBy('company_name')->get(['id', 'company_name']),
            'projects' => \App\Models\Project::orderBy('name')->get(['id', 'name', 'client_id']),
        ])->layout('layouts.app');
    }
}
