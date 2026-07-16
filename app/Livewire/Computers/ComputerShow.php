<?php

namespace App\Livewire\Computers;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ComputerShow extends Component
{
    public Computer $computer;

    public string $softwareSearch = '';

    /** Default view: only software matching managed catalogue packages. */
    public bool $softwareManagedOnly = true;

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

        return view('livewire.computers.computer-show', [
            'health' => $this->healthChecks(),
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
            'recentActivity' => Activity::where('subject_type', Computer::class)
                ->where('subject_id', $this->computer->id)
                ->latest()->limit(5)->get(),
            'diskUsedPercent' => $diskUsedPercent,
            'softwareTotal'   => $this->computer->software()->count(),
            'softwareManaged' => $this->computer->software()
                ->where('source', 'winget')->whereIn('name', $managedPackages->keys())->count(),
            'softwareItems'   => $this->computer->software()
                ->when($this->softwareManagedOnly, fn ($q) => $q
                    ->where('source', 'winget')
                    ->whereIn('name', $managedPackages->keys()))
                ->when($this->softwareSearch !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->softwareSearch}%")
                    ->orWhere('publisher', 'like', "%{$this->softwareSearch}%")))
                ->orderBy('name')
                ->limit(150)
                ->get(),
            'managedPackages' => $managedPackages,
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
