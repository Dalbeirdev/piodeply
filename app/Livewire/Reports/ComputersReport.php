<?php

namespace App\Livewire\Reports;

use App\Enums\DeploymentRing;
use App\Enums\Permission;
use App\Livewire\Concerns\WithCompactPagination;
use App\Models\Computer;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Fleet health: every machine with its ring, agent state and disk
 * pressure — the "what needs attention" report.
 */
class ComputersReport extends Component
{
    use WithCompactPagination;

    public string $projectFilter = '';

    public string $ringFilter = '';

    public string $presence = ''; // '' | online | offline

    public string $softwareStatus = ''; // '' | outdated | uptodate | pending

    public function updating($name, $value): void
    {
        $this->resetPage();
    }

    private function query(): Builder
    {
        $tenantId = auth()->user()->tenantClientId();

        return Computer::query()
            ->with(['project.client'])
            ->withCount('software')
            // Preloaded so healthScore() computes without per-row queries.
            ->withCount([
                'software as updates_available_count' => fn ($q) => $q->whereNotNull('available_version'),
                'deploymentJobs as failed_jobs_count' => fn ($q) => $q->where('status', \App\Enums\JobStatus::Failed),
            ])
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
            ))
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->when($this->ringFilter !== '', fn ($q) => $q->where('ring', $this->ringFilter))
            ->when($this->presence === 'online', fn ($q) => $q->online())
            ->when($this->presence === 'offline', fn ($q) => $q->offline())
            ->when($this->softwareStatus !== '', fn ($q) => $q->softwareStatus($this->softwareStatus))
            ->orderBy('hostname');
    }

    public function export()
    {
        abort_unless(auth()->user()->can(Permission::ReportsExport->value), 403);

        $computers = $this->query()->get();
        $fleetBrowserLatest = app(\App\Services\BrowserVersionService::class)
            ->fleetLatestByClient($computers->pluck('id')->all());

        $csv = "Hostname,Client,".project_term().",Health /100,Health notes,Ring,OS,Build,Agent version,Last seen,Online,RAM,Disk free %,Software entries,Updates required,Serial\n";
        foreach ($computers as $computer) {
            $diskPct = ($computer->disk_total_bytes && $computer->disk_free_bytes !== null)
                ? round($computer->disk_free_bytes / $computer->disk_total_bytes * 100)
                : '';
            $health = $computer->healthScore($fleetBrowserLatest[$computer->project->client_id] ?? null);

            $csv .= implode(',', array_map(
                fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"',
                [
                    $computer->hostname,
                    $computer->project->client->company_name,
                    $computer->project->name,
                    $health['score'],
                    implode('; ', $health['notes']),
                    $computer->ring->label(),
                    $computer->os_name,
                    $computer->windows_build,
                    $computer->agent_version ?? '',
                    $computer->last_seen_at?->format('Y-m-d H:i') ?? 'never',
                    $computer->isOnline() ? 'yes' : 'no',
                    $computer->ramForHumans() ?? '',
                    $diskPct,
                    $computer->software_count,
                    $computer->updates_available_count,
                    $computer->serial_number ?? '',
                ]
            )) . "\n";
        }

        return response()->streamDownload(
            fn () => print($csv),
            'piodeploy-fleet-' . now()->format('Ymd-His') . '.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function exportPdf()
    {
        abort_unless(auth()->user()->can(Permission::ReportsExport->value), 403);

        $computers = $this->query()->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.fleet-health-pdf', [
            'computers'   => $computers,
            'generatedAt' => now(),
            'company'     => app(\App\Services\SettingsService::class)->get('branding.company_name'),
            'fleetBrowserLatest' => app(\App\Services\BrowserVersionService::class)
                ->fleetLatestByClient($computers->pluck('id')->all()),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'piodeploy-fleet-health-' . now()->format('Ymd-His') . '.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    public function render()
    {
        abort_unless(auth()->user()->can(Permission::ReportsView->value), 403);

        $tenantId = auth()->user()->tenantClientId();
        $computers = $this->query()->paginate(20);

        return view('livewire.reports.computers-report', [
            'computers' => $computers,
            'rings'     => DeploymentRing::cases(),
            'projects'  => \App\Models\Project::orderBy('name')
                ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->get(['id', 'name']),
            // One query for the whole page of rows, not one per row — see
            // Computer::healthScore()'s $fleetBrowserLatest parameter.
            'fleetBrowserLatest' => app(\App\Services\BrowserVersionService::class)
                ->fleetLatestByClient($computers->pluck('id')->all()),
        ])->layout('layouts.app');
    }
}
