<?php

namespace App\Livewire\Reports;

use App\Enums\JobStatus;
use App\Enums\Permission;
use App\Livewire\Concerns\WithCompactPagination;
use App\Models\DeploymentJob;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Deployment activity over a date range: success rate, per-status
 * breakdown and the underlying jobs.
 */
class DeploymentsReport extends Component
{
    use WithCompactPagination;

    private const EXPORT_CAP = 5000;

    public string $from = '';

    public string $to = '';

    public string $status = '';

    public string $projectFilter = '';

    public function mount(): void
    {
        $this->from = now()->subDays(30)->format('Y-m-d');
        $this->to = now()->format('Y-m-d');
    }

    public function updating($name, $value): void
    {
        $this->resetPage();
    }

    private function query(): Builder
    {
        $tenantId = auth()->user()->tenantClientId();

        return DeploymentJob::query()
            ->with(['computer.project.client', 'package'])
            ->when($tenantId !== null, fn ($q) => $q->whereHas(
                'computer.project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantId)
            ))
            ->when($this->projectFilter !== '', fn ($q) => $q->whereHas(
                'computer',
                fn ($c) => $c->where('project_id', $this->projectFilter)
            ))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from . ' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', $this->to . ' 23:59:59'))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status));
    }

    private function stats(): array
    {
        $byStatus = (clone $this->query())->reorder()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = $byStatus->sum();
        $succeeded = (int) $byStatus->get(JobStatus::Succeeded->value, 0);
        $failed = (int) $byStatus->get(JobStatus::Failed->value, 0);
        $finished = $succeeded + $failed;

        return [
            'total'        => $total,
            'succeeded'    => $succeeded,
            'failed'       => $failed,
            'in_flight'    => $total - $finished - (int) $byStatus->get(JobStatus::Cancelled->value, 0),
            'cancelled'    => (int) $byStatus->get(JobStatus::Cancelled->value, 0),
            'success_rate' => $finished > 0 ? round($succeeded / $finished * 100, 1) : null,
        ];
    }

    public function export()
    {
        abort_unless(auth()->user()->can(Permission::ReportsExport->value), 403);

        $jobs = $this->query()->orderByDesc('id')->limit(self::EXPORT_CAP)->get();

        $csv = "Job,Date,Computer,Client,Project,Package,Action,Status,Attempts,Exit code,Failure reason\n";
        foreach ($jobs as $job) {
            $csv .= implode(',', array_map(
                fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"',
                [
                    $job->id,
                    $job->created_at->format('Y-m-d H:i'),
                    $job->computer->hostname,
                    $job->computer->project->client->company_name,
                    $job->computer->project->name,
                    $job->package->name,
                    $job->action->label(),
                    $job->status->label(),
                    "{$job->attempts}/{$job->max_attempts}",
                    $job->exit_code ?? '',
                    $job->failure_reason ?? '',
                ]
            )) . "\n";
        }

        return response()->streamDownload(
            fn () => print($csv),
            'piodeploy-deployments-' . now()->format('Ymd-His') . '.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function render()
    {
        abort_unless(auth()->user()->can(Permission::ReportsView->value), 403);

        $tenantId = auth()->user()->tenantClientId();

        return view('livewire.reports.deployments-report', [
            'stats'    => $this->stats(),
            'jobs'     => $this->query()->orderByDesc('id')->paginate(20),
            'statuses' => JobStatus::cases(),
            'projects' => \App\Models\Project::orderBy('name')
                ->when($tenantId !== null, fn ($q) => $q->where('client_id', $tenantId))
                ->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
