<?php

namespace App\Livewire\Computers;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Services\PolicyService;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ComputerShow extends Component
{
    public Computer $computer;

    public string $softwareSearch = '';

    /**
     * managed   — matches a catalogue package (the default; the rest is noise)
     * deployed  — PioDeploy put it here: there is a succeeded install/update
     * outdated  — the machine's package manager is offering something newer
     * all       — everything the machine reported
     */
    public string $softwareFilter = 'managed';

    public function mount(Computer $computer): void
    {
        $this->authorize('view', $computer);
        $this->computer = $computer->load('project.client');
    }

    /**
     * MSP-style health checks derived from inventory + heartbeat data.
     *
     * @return list<array{level: string, message: string}>
     */
    public function healthChecks(): array
    {
        $checks = [];
        $computer = $this->computer;

        if ($computer->last_seen_at === null) {
            $checks[] = ['level' => 'warn', 'message' => 'Agent has never reported in — verify the agent service is installed and running.'];
        } elseif ($computer->last_seen_at->lt(now()->subDay())) {
            $checks[] = ['level' => 'warn', 'message' => 'Offline for ' . $computer->last_seen_at->diffForHumans(null, true) . ' — machine may be decommissioned or the agent stopped.'];
        }

        if ($computer->disk_total_bytes && $computer->disk_free_bytes !== null) {
            $freePercent = $computer->disk_free_bytes / $computer->disk_total_bytes * 100;
            if ($freePercent < 10) {
                $checks[] = ['level' => 'warn', 'message' => 'Low disk space: only ' . Computer::bytesForHumans($computer->disk_free_bytes) . ' (' . round($freePercent) . '%) free on the system drive.'];
            }
        }

        if ($computer->secure_boot === false) {
            $checks[] = ['level' => 'warn', 'message' => 'Secure Boot is disabled.'];
        }

        if ($computer->tpm_enabled === false) {
            $checks[] = ['level' => 'warn', 'message' => 'TPM is disabled.'];
        } elseif ($computer->tpm_enabled === null) {
            $checks[] = ['level' => 'info', 'message' => 'TPM state unknown — the agent runs unelevated; install as a service to read it.'];
        }

        $failed = DeploymentJob::where('computer_id', $computer->id)->where('status', JobStatus::Failed)->count();
        if ($failed > 0) {
            $checks[] = ['level' => 'warn', 'message' => "{$failed} failed deployment " . str('job')->plural($failed) . ' need attention.'];
        }

        return $checks;
    }

    public function render()
    {
        $jobs = DeploymentJob::where('computer_id', $this->computer->id);

        $diskUsedPercent = null;
        if ($this->computer->disk_total_bytes && $this->computer->disk_free_bytes !== null) {
            $diskUsedPercent = round(
                ($this->computer->disk_total_bytes - $this->computer->disk_free_bytes)
                / $this->computer->disk_total_bytes * 100
            );
        }

        // Exact winget-id match -> the software is a managed catalogue package.
        $managedPackages = \App\Models\Package::whereNotNull('winget_id')->pluck('id', 'winget_id');

        // "PioDeploy put this here" = we have a succeeded install/update for
        // the package on this machine. Anything else that is merely in the
        // catalogue arrived some other way, which is the distinction an MSP
        // wants when auditing what it introduced to a client's estate.
        $deployedPackageIds = DeploymentJob::where('computer_id', $this->computer->id)
            ->whereIn('action', [JobAction::Install, JobAction::Update])
            ->where('status', JobStatus::Succeeded)
            ->distinct()
            ->pluck('package_id');

        $deployedNames = $managedPackages
            ->filter(fn (int $packageId) => $deployedPackageIds->contains($packageId))
            ->keys();

        return view('livewire.computers.computer-show', [
            'health' => $this->healthChecks(),
            'readinessIssues' => app(\App\Services\ReadinessService::class)->issues($this->computer),
            'stats'  => [
                'succeeded'   => (clone $jobs)->where('status', JobStatus::Succeeded)->count(),
                'in_flight'   => (clone $jobs)->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
                'failed'      => (clone $jobs)->where('status', JobStatus::Failed)->count(),
                'last_deploy' => (clone $jobs)->where('status', JobStatus::Succeeded)->max('finished_at'),
            ],
            // One row per task, newest first — a package deployed hourly is
            // one line with a count, not eight identical ones.
            'recentJobs' => DeploymentJob::with(['package', 'packageVersion'])
                ->where('computer_id', $this->computer->id)
                ->withRepeatCount()
                ->onlyLatestPerTask()
                ->orderByDesc('id')->limit(8)->get(),
            // Why each policy is or is not acting here — the answer to
            // "so why isn't it installed?" when there is no job to point at.
            'policyExplanations' => app(PolicyService::class)->explainFor($this->computer),
            'jobLog' => DeploymentJob::with(['package'])
                ->where('computer_id', $this->computer->id)
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'recentActivity' => Activity::where('subject_type', Computer::class)
                ->where('subject_id', $this->computer->id)
                ->latest()->limit(5)->get(),
            'diskUsedPercent' => $diskUsedPercent,
            'softwareTotal'   => $this->computer->software()->count(),
            'softwareManaged' => $this->computer->software()
                ->where('source', 'winget')->whereIn('name', $managedPackages->keys())->count(),
            'softwareDeployed' => $this->computer->software()
                ->where('source', 'winget')->whereIn('name', $deployedNames)->count(),
            // Counted in PHP, not SQL: "newer" is a version comparison, and
            // winget sometimes offers an "available" that is not ahead.
            'softwareOutdated' => $this->computer->software()
                ->withUpdateAvailable()->get()->filter->hasUpdate()->count(),
            'softwareItems'   => $this->computer->software()
                ->when($this->softwareFilter === 'managed', fn ($q) => $q
                    ->where('source', 'winget')
                    ->whereIn('name', $managedPackages->keys()))
                ->when($this->softwareFilter === 'deployed', fn ($q) => $q
                    ->where('source', 'winget')
                    ->whereIn('name', $deployedNames))
                ->when($this->softwareFilter === 'outdated', fn ($q) => $q->withUpdateAvailable())
                ->when($this->softwareSearch !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->softwareSearch}%")
                    ->orWhere('publisher', 'like', "%{$this->softwareSearch}%")))
                ->orderBy('name')
                ->limit(150)
                ->get(),
            'managedPackages' => $managedPackages,
            'deployedNames'   => $deployedNames,
            'browserPolicyRows' => \App\Models\BrowserPolicy::query()
                ->where('project_id', $this->computer->project_id)
                ->where('status', 'active')
                ->with(['results' => fn ($q) => $q->where('computer_id', $this->computer->id)])
                ->get()
                ->map(fn ($policy) => [
                    'policy'   => $policy,
                    'excluded' => $policy->excludedComputers()->whereKey($this->computer->id)->exists(),
                    'results'  => $policy->results->keyBy('browser'),
                ]),
        ])->layout('layouts.app');
    }
}
