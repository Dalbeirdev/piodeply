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

        $fleetUpdates = app(\App\Services\FleetUpdateService::class);
        $updates = $fleetUpdates->pending();   // one pass; byPackage reuses it

        $stats = [
            'online'    => Computer::online()->count(),
            'offline'   => Computer::offline()->count(),
            'pending'   => DeploymentJob::whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
            'failed'    => DeploymentJob::where('status', JobStatus::Failed)->count(),
            'outdated'  => $updates->count(),
            // One update across sixty machines is one decision, not sixty.
            'outdated_machines' => $updates->pluck('computer_id')->unique()->count(),
            // Machines whose PioDeploy agent itself is behind the latest build.
            'outdated_agents' => Computer::agentOutdated()->count(),
            'latest_agent'    => Computer::latestAgentVersion(),
            'software'  => ComputerSoftware::count(),
            'not_ready' => app(\App\Services\ReadinessService::class)->notReadyCount(),
            'licenses'  => $this->licenseUsage(),
            'clients'   => Client::count(),
            'projects'  => Project::count(),
            'packages'  => Package::active()->count(),
            'today'     => Activity::whereDate('created_at', Carbon::today())->count(),
            'health'    => $this->averageHealth(),
        ];

        return view('livewire.dashboard', [
            'stats'         => $stats,
            'updatesByPackage' => $fleetUpdates->byPackage(pending: $updates)->take(6),
            'fleetByClient' => $this->fleetByClient(),
            'series'        => $this->deploymentsSeries(),
            'activity'      => Activity::with('causer')->latest()->limit(8)->get(),
            'browserPolicySummary' => app(\App\Services\BrowserPolicyService::class)->fleetSummary(),
        ])->layout('layouts.app');
    }

    private function renderClientPortal(int $clientId)
    {
        // A Client-role account with no client binding (tenant id 0) has
        // nothing to show — a friendly notice beats a 404.
        $client = Client::find($clientId);
        if ($client === null) {
            return view('livewire.client-unbound')->layout('layouts.app');
        }

        $projects = Project::where('client_id', $clientId)
            // Per-project confinement: an assigned technician's dashboard
            // covers exactly their projects.
            ->when(auth()->user()->visibleProjectIds() !== null,
                fn ($q) => $q->whereIn('id', auth()->user()->visibleProjectIds()))
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
            'health'  => $this->averageHealth((clone $computers)->pluck('id')->all()),
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

    /**
     * The fleet's average healthScore() — one number for "how are we
     * doing", with the weakest machine named so the number is actionable.
     * Null when there are no machines yet. Counts are preloaded, so this
     * is one query however large the fleet.
     *
     * @param  list<int>|null  $computerIds  confine to these machines (client portal)
     * @return array{avg: int, count: int, worst: string, worst_score: int}|null
     */
    private function averageHealth(?array $computerIds = null): ?array
    {
        $computers = Computer::query()
            ->when($computerIds !== null, fn ($q) => $q->whereIn('id', $computerIds))
            // project:client_id — healthScore()'s browser check needs the
            // client id per row; without this it is one query per computer.
            ->with('project:id,client_id')
            ->withCount([
                'software as updates_available_count' => fn ($q) => $q->whereNotNull('available_version'),
                'deploymentJobs as failed_jobs_count' => fn ($q) => $q->where('status', JobStatus::Failed),
            ])
            ->get();

        if ($computers->isEmpty()) {
            return null;
        }

        // One query for whichever clients are represented here, not one per
        // computer — see Computer::healthScore()'s $fleetBrowserLatest param.
        $fleetBrowserLatest = app(\App\Services\BrowserVersionService::class)
            ->fleetLatestByClient($computers->pluck('id')->all());

        $scored = $computers->map(fn (Computer $c) => [
            'hostname' => $c->hostname,
            'score'    => $c->healthScore($fleetBrowserLatest[$c->project->client_id] ?? null)['score'],
        ]);
        $worst = $scored->sortBy('score')->first();

        return [
            'avg'         => (int) round($scored->avg('score')),
            'count'       => $scored->count(),
            'worst'       => $worst['hostname'],
            'worst_score' => $worst['score'],
        ];
    }
}
