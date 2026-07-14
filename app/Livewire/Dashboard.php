<?php

namespace App\Livewire;

use App\Enums\JobStatus;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Dashboard extends Component
{
    /**
     * Managed software whose reported version differs from the package's
     * pinned latest version. Only computable where a latest version is
     * pinned (binary packages); winget entries resolve latest at install.
     */
    private function outdatedSoftwareCount(): int
    {
        return ComputerSoftware::query()
            ->join('packages', function ($join) {
                $join->on('packages.winget_id', '=', 'computer_software.name')
                    ->where('computer_software.source', 'winget');
            })
            ->join('package_versions', function ($join) {
                $join->on('package_versions.package_id', '=', 'packages.id')
                    ->where('package_versions.is_latest', true);
            })
            ->whereNotNull('computer_software.version')
            ->whereColumn('computer_software.version', '!=', 'package_versions.version')
            ->count();
    }

    private function licenseUsage(): int
    {
        return ComputerSoftware::query()
            ->join('packages', function ($join) {
                $join->on('packages.winget_id', '=', 'computer_software.name')
                    ->where('computer_software.source', 'winget');
            })
            ->whereIn('packages.license', ['Commercial', 'Trialware'])
            ->count();
    }

    /** @return list<array{name: string, online: int, offline: int}> */
    private function fleetByClient(): array
    {
        return Client::query()
            ->with(['projects' => fn ($q) => $q->withTrashed()])
            ->get()
            ->map(function (Client $client) {
                $computers = Computer::whereIn('project_id', $client->projects->pluck('id'));
                $online = (clone $computers)->online()->count();
                $total = $computers->count();

                return [
                    'name'    => $client->company_name,
                    'online'  => $online,
                    'offline' => $total - $online,
                    'total'   => $total,
                ];
            })
            ->filter(fn (array $row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->take(8)
            ->values()
            ->all();
    }

    /** @return list<array{day: string, label: string, succeeded: int, failed: int, other: int}> */
    private function deploymentsSeries(): array
    {
        $since = now()->subDays(13)->startOfDay();

        $jobs = DeploymentJob::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'status'])
            ->groupBy(fn (DeploymentJob $job) => $job->created_at->toDateString());

        return collect(range(13, 0))
            ->map(function (int $daysAgo) use ($jobs) {
                $date = now()->subDays($daysAgo);
                $day = $date->toDateString();
                $group = $jobs->get($day, collect());

                return [
                    'day'       => $day,
                    'label'     => $date->format('d M'),
                    'succeeded' => $group->where('status', JobStatus::Succeeded)->count(),
                    'failed'    => $group->where('status', JobStatus::Failed)->count(),
                    'other'     => $group->whereNotIn('status', [JobStatus::Succeeded, JobStatus::Failed])->count(),
                ];
            })
            ->all();
    }

    public function render()
    {
        // Client-bound users get their own portal view, scoped to their data.
        $tenantId = auth()->user()->tenantClientId();
        if ($tenantId !== null) {
            return $this->renderClientPortal($tenantId);
        }

        $stats = [
            'online'    => Computer::online()->count(),
            'offline'   => Computer::offline()->count(),
            'pending'   => DeploymentJob::whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
            'failed'    => DeploymentJob::where('status', JobStatus::Failed)->count(),
            'outdated'  => $this->outdatedSoftwareCount(),
            'software'  => ComputerSoftware::count(),
            'licenses'  => $this->licenseUsage(),
            'clients'   => Client::count(),
            'projects'  => Project::count(),
            'packages'  => Package::active()->count(),
            'today'     => Activity::whereDate('created_at', Carbon::today())->count(),
        ];

        return view('livewire.dashboard', [
            'stats'         => $stats,
            'fleetByClient' => $this->fleetByClient(),
            'series'        => $this->deploymentsSeries(),
            'activity'      => Activity::with('causer')->latest()->limit(8)->get(),
        ])->layout('layouts.app');
    }

    private function renderClientPortal(int $clientId)
    {
        $client = Client::findOrFail($clientId);

        $projects = Project::where('client_id', $clientId)
            ->withCount('computers')
            ->orderBy('name')
            ->get();

        $computers = Computer::whereIn('project_id', $projects->pluck('id'));

        $stats = [
            'online'  => (clone $computers)->online()->count(),
            'offline' => (clone $computers)->offline()->count(),
            'pending' => DeploymentJob::whereIn('computer_id', (clone $computers)->pluck('id'))
                ->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
            'failed'  => DeploymentJob::whereIn('computer_id', (clone $computers)->pluck('id'))
                ->where('status', JobStatus::Failed)->count(),
        ];

        return view('livewire.client-dashboard', [
            'client'     => $client,
            'projects'   => $projects,
            'computers'  => (clone $computers)->orderBy('hostname')->limit(10)->get(),
            'stats'      => $stats,
            'recentJobs' => DeploymentJob::with(['computer', 'package'])
                ->whereIn('computer_id', (clone $computers)->pluck('id'))
                ->orderByDesc('id')->limit(8)->get(),
        ])->layout('layouts.app');
    }
}
