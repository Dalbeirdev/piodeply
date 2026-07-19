<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Services\ClientService;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Livewire\Concerns\WithCompactPagination;

class ClientsIndex extends Component
{
    use WithFileUploads;
    use WithCompactPagination;

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

    /** Opt a client in/out of the monthly emailed compliance PDF. */
    public function toggleMonthlyReport(int $clientId): void
    {
        $client = Client::findOrFail($clientId);
        $this->authorize('update', $client);

        $client->update(['monthly_report' => ! $client->monthly_report]);
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
