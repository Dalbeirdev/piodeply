<?php

namespace App\Services;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeploymentService
{
    /**
     * Queue a job for a computer. A job with a dependency starts Blocked
     * and is released to Pending when the dependency succeeds.
     */
    public function queue(
        Computer $computer,
        Package $package,
        JobAction $action,
        int $priority = 5,
        ?int $packageVersionId = null,
        ?DeploymentJob $dependsOn = null,
        ?int $createdBy = null,
    ): DeploymentJob {
        $status = $dependsOn !== null && ! $dependsOn->status->isTerminal()
            ? JobStatus::Blocked
            : JobStatus::Pending;

        return DeploymentJob::create([
            'computer_id'        => $computer->id,
            'package_id'         => $package->id,
            'package_version_id' => $packageVersionId,
            'action'             => $action,
            'status'             => $status,
            'priority'           => max(1, min(10, $priority)),
            'depends_on_job_id'  => $dependsOn?->id,
            'created_by'         => $createdBy,
        ]);
    }

    public function pendingCountFor(Computer $computer): int
    {
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('status', JobStatus::Pending)
            ->count();
    }

    /**
     * Atomically claim the next pending jobs for a computer (highest
     * priority first) and mark them running. Row locks prevent two agent
     * polls from grabbing the same job.
     *
     * @return Collection<int, DeploymentJob>
     */
    public function claimFor(Computer $computer, int $limit = 5): Collection
    {
        return DB::transaction(function () use ($computer, $limit) {
            $jobs = DeploymentJob::query()
                ->where('computer_id', $computer->id)
                ->claimable()
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($jobs as $job) {
                $job->update([
                    'status'     => JobStatus::Running,
                    'attempts'   => $job->attempts + 1,
                    'claimed_at' => $now,
                ]);
            }

            return $jobs->loadMissing(['package', 'packageVersion']);
        });
    }

    /**
     * Record an agent's result. A failed job that still has retries left
     * returns to Pending; otherwise it is terminal. Success releases any
     * jobs waiting on it.
     */
    public function reportResult(DeploymentJob $job, bool $success, ?int $exitCode, ?string $log, ?string $failureReason = null): DeploymentJob
    {
        return DB::transaction(function () use ($job, $success, $exitCode, $log, $failureReason) {
            if ($success) {
                $job->update([
                    'status'         => JobStatus::Succeeded,
                    'exit_code'      => $exitCode,
                    'output_log'     => $log,
                    'failure_reason' => null,
                    'finished_at'    => now(),
                ]);
                $this->releaseDependents($job);
            } elseif ($job->canRetry()) {
                // Back into the queue for another agent pass.
                $job->update([
                    'status'         => JobStatus::Pending,
                    'exit_code'      => $exitCode,
                    'output_log'     => $log,
                    'failure_reason' => $failureReason,
                    'claimed_at'     => null,
                ]);
            } else {
                $job->update([
                    'status'         => JobStatus::Failed,
                    'exit_code'      => $exitCode,
                    'output_log'     => $log,
                    'failure_reason' => $failureReason,
                    'finished_at'    => now(),
                ]);
                $this->failDependents($job);
            }

            return $job->fresh();
        });
    }

    /** Manually requeue a failed/cancelled job (resets the attempt counter). */
    public function retry(DeploymentJob $job): DeploymentJob
    {
        $job->update([
            'status'         => JobStatus::Pending,
            'attempts'       => 0,
            'claimed_at'     => null,
            'finished_at'    => null,
            'exit_code'      => null,
            'failure_reason' => null,
        ]);

        return $job;
    }

    public function cancel(DeploymentJob $job): DeploymentJob
    {
        if (! $job->status->isTerminal()) {
            $job->update(['status' => JobStatus::Cancelled, 'finished_at' => now()]);
            $this->failDependents($job);
        }

        return $job;
    }

    private function releaseDependents(DeploymentJob $job): void
    {
        DeploymentJob::where('depends_on_job_id', $job->id)
            ->where('status', JobStatus::Blocked)
            ->update(['status' => JobStatus::Pending]);
    }

    private function failDependents(DeploymentJob $job): void
    {
        // A blocked job whose dependency can never succeed is cancelled.
        DeploymentJob::where('depends_on_job_id', $job->id)
            ->where('status', JobStatus::Blocked)
            ->update(['status' => JobStatus::Cancelled, 'finished_at' => now()]);
    }
}
