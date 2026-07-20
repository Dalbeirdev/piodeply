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
     * deployed  — PioDeploy put it here (the default: what we're accountable for)
     * managed   — matches a catalogue package, however it arrived
     * outdated  — the machine's package manager is offering something newer
     * all       — everything the machine reported
     */
    public string $softwareFilter = 'deployed';

    public function mount(Computer $computer): void
    {
        $this->authorize('view', $computer);
        $this->computer = $computer->load('project.client');
    }

    /**
     * One-click "Update now" from the software table: queues an update job
     * for the catalogue package behind an outdated winget row, through the
     * same guarded path as the deploy form.
     */
    public function queueUpdate(int $softwareId, \App\Services\DeploymentService $deployments): void
    {
        $this->authorize('create', \App\Models\DeploymentJob::class);

        $item = $this->computer->software()->findOrFail($softwareId);

        $package = \App\Models\Package::active()
            ->where('winget_id', $item->name)
            ->first();

        if ($package === null) {
            session()->flash('status', "{$item->name} is not in the catalogue — add it as a package to manage its updates.");

            return;
        }

        $result = $deployments->queueIfNeeded(
            computer: $this->computer,
            package: $package,
            action: \App\Enums\JobAction::Update,
            createdBy: auth()->id(),
        );

        session()->flash('status', $result->message);
    }

    /**
     * Queues a full agent reinstall: on its next heartbeat the machine
     * re-downloads the current bundle and swaps itself, whatever state its
     * install is in. This is the remote fix for a broken agent that is
     * still checking in — no one touches the machine by hand.
     */
    public function requestReinstall(): void
    {
        $this->authorize('update', $this->computer);

        $this->computer->forceFill(['reinstall_requested_at' => now()])->save();

        session()->flash('status', 'Reinstall queued — the agent will replace itself at its next check-in (within ~1 minute while online).');
    }

    /**
     * Queues agent removal. The machine uninstalls its own agent at the next
     * heartbeat: service deleted, files removed. The computer record stays
     * (with its history) until someone deletes it here.
     */
    public function requestUninstall(): void
    {
        $this->authorize('delete', $this->computer);

        $this->computer->forceFill(['uninstall_requested_at' => now()])->save();

        session()->flash('status', 'Uninstall queued — the agent will remove itself from the machine at its next check-in. The computer record and its history remain until you delete them.');
    }

    /** Withdraws a queued command the agent has not collected yet. */
    public function cancelAgentCommand(): void
    {
        $this->authorize('update', $this->computer);

        $this->computer->forceFill([
            'reinstall_requested_at' => null,
            'uninstall_requested_at' => null,
        ])->save();

        session()->flash('status', 'Pending agent command cancelled.');
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
