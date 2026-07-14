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
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
            ))
            ->when($this->projectFilter !== '', fn ($q) => $q->where('project_id', $this->projectFilter))
            ->when($this->ringFilter !== '', fn ($q) => $q->where('ring', $this->ringFilter))
            ->when($this->presence === 'online', fn ($q) => $q->online())
            ->when($this->presence === 'offline', fn ($q) => $q->offline())
            ->orderBy('hostname');
    }

    public function export()
    {
        abort_unless(auth()->user()->can(Permission::ReportsExport->value), 403);

        $csv = "Hostname,Client,Project,Ring,OS,Build,Agent version,Last seen,Online,RAM,Disk free %,Software entries,Serial\n";
        foreach ($this->query()->get() as $computer) {
            $diskPct = ($computer->disk_total_bytes && $computer->disk_free_bytes !== null)
                ? round($computer->disk_free_bytes / $computer->disk_total_bytes * 100)
                : '';

            $csv .= implode(',', array_map(
                fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"',
                [
                    $computer->hostname,
                    $computer->project->client->company_name,
                    $computer->project->name,
                    $computer->ring->label(),
                    $computer->os_name,
                    $computer->windows_build,
                    $computer->agent_version ?? '',
                    $computer->last_seen_at?->format('Y-m-d H:i') ?? 'never',
                    $computer->isOnline() ? 'yes' : 'no',
                    $computer->ramForHumans() ?? '',
                    $diskPct,
                    $computer->software_count,
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

    public function render()
    {
        abort_unless(auth()->user()->can(Permission::ReportsView->value), 403);

        $tenantId = auth()->user()->tenantClientId();

        return view('livewire.reports.computers-report', [
            'computers' => $this->query()->paginate(20),
            'rings'     => DeploymentRing::cases(),
            'projects'  => \App\Models\Project::orderBy('name')
                ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
