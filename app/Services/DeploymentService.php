<?php

namespace App\Services;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\QueueOutcome;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeploymentService
{
    public function __construct(
        private readonly InstalledStateService $installedState,
    ) {
    }

    /**
     * Queue a job for a computer. A job with a dependency starts Blocked
     * and is released to Pending when the dependency succeeds.
     *
     * This is the unguarded writer: it queues whatever it is told to. Call
     * queueIfNeeded() for operator-driven requests, which skips work that
     * would change nothing.
     */
    public function queue(
        Computer $computer,
        Package $package,
        JobAction $action,
        int $priority = 5,
        ?int $packageVersionId = null,
        ?DeploymentJob $dependsOn = null,
        ?int $createdBy = null,
        ?string $targetVersion = null,
    ): DeploymentJob {
        $status = $dependsOn !== null && ! $dependsOn->status->isTerminal()
            ? JobStatus::Blocked
            : JobStatus::Pending;

        return DeploymentJob::create([
            'computer_id'        => $computer->id,
            'package_id'         => $package->id,
            'package_version_id' => $packageVersionId,
            'target_version'     => $targetVersion,
            // Snapshot for the audit trail: what was on the machine when we
            // decided to act. One extra query, and only when a job is really
            // created, so evaluation loops are unaffected.
            'installed_version_before' => $this->installedState->stateOf($package, $computer)['version'],
            'action'             => $action,
            'status'             => $status,
            'priority'           => max(1, min(10, $priority)),
            'max_attempts'       => (int) app(\App\Services\SettingsService::class)
                ->get('deployments.default_max_attempts'),
            'depends_on_job_id'  => $dependsOn?->id,
            'created_by'         => $createdBy,
        ]);
    }

    /**
     * Queue only if it would change something. Guards against the two ways
     * an operator produces noise: asking twice while a job is still in
     * flight, and asking for software the machine already has.
     *
     * $force queues regardless (repairing a broken install is legitimate),
     * but still collapses onto an in-flight duplicate.
     */
    public function queueIfNeeded(
        Computer $computer,
        Package $package,
        JobAction $action,
        int $priority = 5,
        ?int $createdBy = null,
        ?string $targetVersion = null,
        bool $force = false,
    ): QueueResult {
        // Roll back to what? The agent can build no command from this, so the
        // job fails, retries twice more, and reports something unhelpful. The
        // policy engine never asks for this; only a hand-made request can.
        if ($action === JobAction::Rollback && $targetVersion === null) {
            return new QueueResult(
                QueueOutcome::Invalid,
                null,
                'A rollback needs the version to roll back to — pin one and try again.',
            );
        }

        $inFlight = DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $package->id)
            ->where('action', $action)
            ->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])
            ->orderByDesc('id')
            ->first();

        if ($inFlight !== null) {
            return new QueueResult(
                QueueOutcome::AlreadyQueued,
                $inFlight,
                "{$package->name} is already queued on {$computer->hostname} ({$inFlight->status->label()}).",
            );
        }

        $state = $this->installedState->stateOf($package, $computer);

        if (! $force && $this->installedState->isSatisfiedBy($state, $action, $targetVersion)) {
            return new QueueResult(
                QueueOutcome::AlreadySatisfied,
                null,
                $this->satisfiedMessage($package, $computer, $action, $state['version']),
            );
        }

        $job = $this->queue(
            computer: $computer,
            package: $package,
            action: $action,
            priority: $priority,
            createdBy: $createdBy,
            targetVersion: $targetVersion,
        );

        return new QueueResult(QueueOutcome::Queued, $job, "{$package->name} queued on {$computer->hostname}.");
    }

    private function satisfiedMessage(Package $package, Computer $computer, JobAction $action, ?string $version): string
    {
        if ($action === JobAction::Uninstall) {
            return "{$package->name} is not installed on {$computer->hostname} — nothing to remove.";
        }

        $at = $version !== null ? " ({$version})" : '';

        return "{$package->name}{$at} is already installed on {$computer->hostname} — nothing to do.";
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
    public function reportResult(DeploymentJob $job, bool $success, ?int $exitCode, ?string $log, ?string $failureReason = null, ?string $installedVersion = null): DeploymentJob
    {
        $job = $this->persistResult($job, $success, $exitCode, $log, $failureReason, $installedVersion);

        // Outside the transaction, fault-isolated: a notification failure
        // must never make an agent re-report a result.
        if ($job->status === JobStatus::Failed) {
            app(\App\Services\NotificationService::class)->notify('job.failed', "Deployment failed: {$job->package->name} on {$job->computer->hostname}", [
                'computer'       => $job->computer->hostname,
                'client'         => $job->computer->project->client->company_name,
                'package'        => $job->package->name,
                'action'         => $job->action->label(),
                'attempts'       => "{$job->attempts}/{$job->max_attempts}",
                'exit_code'      => $exitCode,
                'failure_reason' => $failureReason,
            ]);
        }

        return $job;
    }

    private function persistResult(DeploymentJob $job, bool $success, ?int $exitCode, ?string $log, ?string $failureReason = null, ?string $installedVersion = null): DeploymentJob
    {
        return DB::transaction(function () use ($job, $success, $exitCode, $log, $failureReason, $installedVersion) {
            // Agents older than 1.3.0 send no version; keep whatever an
            // earlier attempt recorded rather than blanking it.
            $observed = $installedVersion !== null
                ? ['installed_version_after' => $installedVersion]
                : [];

            if ($success) {
                $job->update([
                    'status'         => JobStatus::Succeeded,
                    'exit_code'      => $exitCode,
                    'output_log'     => $log,
                    'failure_reason' => null,
                    'finished_at'    => now(),
                    ...$observed,
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
                    ...$observed,
                ]);
            } else {
                $job->update([
                    'status'         => JobStatus::Failed,
                    'exit_code'      => $exitCode,
                    'output_log'     => $log,
                    'failure_reason' => $failureReason,
                    'finished_at'    => now(),
                    ...$observed,
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
