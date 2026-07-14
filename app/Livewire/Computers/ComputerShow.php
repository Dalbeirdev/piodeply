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

        return view('livewire.computers.computer-show', [
            'health' => $this->healthChecks(),
            'stats'  => [
                'succeeded'   => (clone $jobs)->where('status', JobStatus::Succeeded)->count(),
                'in_flight'   => (clone $jobs)->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])->count(),
                'failed'      => (clone $jobs)->where('status', JobStatus::Failed)->count(),
                'last_deploy' => (clone $jobs)->where('status', JobStatus::Succeeded)->max('finished_at'),
            ],
            'recentJobs' => DeploymentJob::with(['package'])
                ->where('computer_id', $this->computer->id)
                ->orderByDesc('id')->limit(8)->get(),
            'recentActivity' => Activity::where('subject_type', Computer::class)
                ->where('subject_id', $this->computer->id)
                ->latest()->limit(5)->get(),
            'diskUsedPercent' => $diskUsedPercent,
        ])->layout('layouts.app');
    }
}
