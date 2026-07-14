<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Services\ClientService;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ClientsIndex extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public bool $showTrashed = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $importFile = null;

    public string $importSummary = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingShowTrashed(): void
    {
        $this->resetPage();
    }

    public function delete(int $clientId, ClientService $service): void
    {
        $client = Client::findOrFail($clientId);
        $this->authorize('delete', $client);

        $service->delete($client);
    }

    public function restore(int $clientId, ClientService $service): void
    {
        $client = Client::withTrashed()->findOrFail($clientId);
        $this->authorize('restore', $client);

        $service->restore($client);
    }

    public function export(ClientService $service)
    {
        $this->authorize('viewAny', Client::class);

        $csv = $service->exportCsv();

        return response()->streamDownload(
            fn () => print($csv),
            'piodeploy-clients-' . now()->format('Ymd-His') . '.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function import(ClientService $service): void
    {
        $this->authorize('create', Client::class);

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $result = $service->importCsv($this->importFile->get());

        $summary = "Imported {$result['imported']}, updated {$result['updated']}.";
        if ($result['skipped'] !== []) {
            $summary .= ' Skipped lines: ' . implode('; ', array_map(
                fn ($line, $reason) => "#{$line} {$reason}",
                array_keys($result['skipped']),
                $result['skipped']
            ));
        }
        $this->importSummary = $summary;
        $this->importFile = null;
    }

    public function render(ClientRepositoryInterface $clients)
    {
        $this->authorize('viewAny', Client::class);

        return view('livewire.clients.clients-index', [
            'clients' => $clients->searchPaginated(
                search: $this->search,
                status: $this->status ?: null,
                withTrashed: $this->showTrashed,
            ),
            'statuses' => \App\Enums\ClientStatus::cases(),
        ])->layout('layouts.app');
    }
}
