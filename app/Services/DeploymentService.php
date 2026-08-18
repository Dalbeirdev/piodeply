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
        // The tenant-isolation line, drawn where every deployment funnels
        // through: a client's private package never lands on another
        // client's machine. Role does not matter — Super Admin included —
        // because the guard reads ownership, not the caller.
        if (! $package->isUsableFor($computer->project)) {
            throw new \DomainException(
                "\"{$package->name}\" is private to {$package->client?->company_name} and cannot be "
                .'deployed to another client\'s machines.'
            );
        }

        // Custom client-role overlay, enforced at the same funnel: when the
        // signed-in user carries one, both the action capability and the
        // machine scope must allow this job. System-originated queueing
        // (policy engine, agent callbacks) has no signed-in user and is
        // governed by the policies themselves.
        $actor = auth()->user();
        if ($actor !== null && ! $actor->mayDeploy($action->value, $computer, $package)) {
            throw new \DomainException(
                "Your role \"{$actor->clientRole?->name}\" does not allow "
                ."{$action->value} of \"{$package->name}\" on {$computer->hostname}."
            );
        }

        // Approval gate: the action is ALLOWED by the role (checked above),
        // but this role routes it through the account owner. File a request
        // instead of a job — once, not per click.
        if ($actor !== null && ($actor->clientRole?->requires_approval ?? false)) {
            $pending = \App\Models\DeploymentRequest::firstOrCreate([
                'client_id'    => $actor->tenantClientId(),
                'requester_id' => $actor->id,
                'computer_id'  => $computer->id,
                'package_id'   => $package->id,
                'action'       => $action->value,
                'status'       => 'pending',
            ], [
                'target_version' => $targetVersion,
            ]);

            if ($pending->wasRecentlyCreated) {
                app(NotificationService::class)->notify(
                    'deployment.approval_requested',
                    "Approval needed: {$actor->name} wants to {$action->value} {$package->name} on {$computer->hostname}",
                    [
                        'requester' => $actor->name,
                        'package'   => $package->name,
                        'computer'  => $computer->hostname,
                        'action'    => $action->value,
                    ],
                );
            }

            throw new \App\Exceptions\ApprovalRequiredException(
                "Sent for approval: {$action->value} {$package->name} on {$computer->hostname}. "
                .'Your administrator will approve or reject it.'
            );
        }

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
        // The package's installer type has to be able to carry out the action
        // at all. A rollback on an MSI, or an uninstall on a portable EXE, can
        // never succeed — refuse it here instead of queueing three doomed
        // attempts. This mirrors InstallerType's capability matrix.
        if (! $package->installer_type->supports($action)) {
            return new QueueResult(
                QueueOutcome::Invalid,
                null,
                $action === JobAction::Rollback
                    ? "{$package->name} is a {$package->installer_type->label()} package — rollback only works for winget and Chocolatey, which can reinstall a specific earlier version."
                    : "{$package->name} is a {$package->installer_type->label()} package, which does not support {$action->label()}.",
            );
        }

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

        try {
            $job = $this->queue(
                computer: $computer,
                package: $package,
                action: $action,
                priority: $priority,
                createdBy: $createdBy,
                targetVersion: $targetVersion,
            );
        } catch (\App\Exceptions\ApprovalRequiredException $e) {
            // Not a failure: the request is filed; the outcome says so, and
            // bulk fans keep going so every machine gets its own request.
            return new QueueResult(QueueOutcome::ApprovalRequested, null, $e->getMessage());
        }

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

    /**
     * The version this machine ran before its most recent change to this
     * package — the natural "roll back to last known-good" target. Read from
     * the installed_version_before we snapshot on every job, so no manual
     * version hunting is needed. Null when the type can't roll back or there
     * is no earlier version to return to.
     */
    public function previousGoodVersion(Package $package, Computer $computer): ?string
    {
        if (! $package->installer_type->supportsRollback()) {
            return null;
        }

        $current = $this->installedState->stateOf($package, $computer)['version'];

        return DeploymentJob::query()
            ->where('computer_id', $computer->id)
            ->where('package_id', $package->id)
            ->where('status', JobStatus::Succeeded)
            ->whereNotNull('installed_version_before')
            ->orderByDesc('id')
            ->pluck('installed_version_before')
            // The most recent job whose "before" is genuinely older than what
            // is on the machine now — i.e. the version we last upgraded away
            // from. A no-op job (before == current) is skipped.
            ->first(fn (string $before) => $current === null || version_compare($before, $current, '<'));
    }

    /**
     * Fan a single package/action out across many machines, each through the
     * guarded queueIfNeeded path — so satisfied machines are skipped, in-flight
     * duplicates collapse, and unsupported combinations are refused, exactly
     * as for a single deploy. Returns a tally, never a pile of noise.
     *
     * @param  iterable<int, Computer>  $computers
     */
    public function queueBulk(
        iterable $computers,
        Package $package,
        JobAction $action,
        int $priority = 5,
        ?int $createdBy = null,
        ?string $targetVersion = null,
        bool $force = false,
    ): BulkQueueResult {
        $queued = $skipped = $refused = $requested = $total = 0;

        foreach ($computers as $computer) {
            $total++;
            $result = $this->queueIfNeeded($computer, $package, $action, $priority, $createdBy, $targetVersion, $force);

            match ($result->outcome) {
                QueueOutcome::Queued            => $queued++,
                QueueOutcome::Invalid           => $refused++,
                QueueOutcome::ApprovalRequested => $requested++,
                default                         => $skipped++, // AlreadyQueued / AlreadySatisfied
            };
        }

        return new BulkQueueResult($queued, $skipped, $refused, $total, $requested);
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
    /** How long the same cause stays quiet after being reported once. */
    public const ESCALATION_QUIET_HOURS = 6;

    /**
     * Report a terminal failure to whoever can act on it — once.
     *
     * A package that cannot install anywhere fails on every machine it is
     * sent to, and one notification per machine buries the single fact that
     * matters under forty copies of it. Package-level causes are therefore
     * announced once per package; a machine-level cause (no disk, access
     * denied) is genuinely per-machine and stays that way.
     */
    private function escalate(DeploymentJob $job, ?int $exitCode, ?string $failureReason): void
    {
        $kind = $job->failureKind();

        // Machine problems belong to that machine; everything else is one
        // fact about a package, however many machines reported it.
        $scope = $kind === \App\Enums\FailureKind::Machine
            ? 'computer:'.$job->computer_id
            : 'package:'.$job->package_id;

        $key = 'escalation:'.$kind->value.':'.$scope.':'.($exitCode ?? 'none');

        if (! \Illuminate\Support\Facades\Cache::add($key, true, now()->addHours(self::ESCALATION_QUIET_HOURS))) {
            return; // already reported this cause recently
        }

        app(\App\Services\NotificationService::class)->notify(
            'job.failed',
            "Deployment failed: {$job->package->name} on {$job->computer->hostname}",
            [
                'computer'       => $job->computer->hostname,
                'client'         => $job->computer->project->client->company_name,
                'package'        => $job->package->name,
                'action'         => $job->action->label(),
                'attempts'       => "{$job->attempts}/{$job->max_attempts}",
                'exit_code'      => $exitCode,
                'failure_reason' => $failureReason,
                // What kind of problem this is, and therefore who fixes it.
                'kind'           => $kind->label(),
                'owner'          => $kind->ownedByOperator()
                    ? 'Platform administrator — the package or catalogue needs changing'
                    : 'Whoever manages this machine — nothing is wrong with the package',
                'what_to_do'     => $job->failureHint(),
            ]
        );
    }

    public function reportResult(DeploymentJob $job, bool $success, ?int $exitCode, ?string $log, ?string $failureReason = null, ?string $installedVersion = null): DeploymentJob
    {
        $job = $this->persistResult($job, $success, $exitCode, $log, $failureReason, $installedVersion);

        // Outside the transaction, fault-isolated: a notification failure
        // must never make an agent re-report a result.
        if ($job->status === JobStatus::Failed) {
            $this->escalate($job, $exitCode, $failureReason);
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
            } elseif ($job->canRetry() && DeploymentJob::classifyFailure($exitCode)->shouldRetry()) {
                // Back into the queue for another agent pass — but only for a
                // failure a retry could actually clear. A package that cannot
                // install here, a machine out of disk, or a code we have never
                // seen all fail identically on the next pass; repeating them
                // just delays the person who can fix it.
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
