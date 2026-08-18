<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\DeploymentFailureDismissal;
use App\Models\DeploymentJob;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The "Needs attention" queue: every failure cause that is still live,
 * grouped exactly the way escalate() groups them for notifications, so the
 * portal and the inbox never disagree about what "one problem" means.
 *
 * A cause leaves the queue two ways: automatically, when a later job for the
 * same scope succeeds (the fix already worked, nobody needs to click
 * anything); or manually, when an operator marks it handled. A dismissal is
 * tied to the failure that existed at the time — a fresh failure of the same
 * cause has a higher job id and reappears rather than staying silenced.
 */
class FailureQueueService
{
    /** Bounds a pathological backlog; ordinary fleets never approach this. */
    private const MAX_SCANNED = 2000;

    /**
     * @return Collection<int, array{
     *   cause_key: string, kind: \App\Enums\FailureKind, owner: string,
     *   package: ?\App\Models\Package, computer: ?\App\Models\Computer,
     *   exit_code: ?int, failure_reason: ?string, hint: ?string,
     *   affected_computers: int, first_seen: \Illuminate\Support\Carbon,
     *   last_seen: \Illuminate\Support\Carbon, latest_job: DeploymentJob,
     * }>
     */
    public function unresolvedCauses(User $viewer): Collection
    {
        $allowed = $viewer->visibleProjectIds();
        $tenantId = $viewer->tenantClientId();

        $failures = DeploymentJob::query()
            ->where('status', JobStatus::Failed)
            ->whereHas('computer', function ($q) use ($allowed, $tenantId) {
                // visibleProjectIds() alone is not the tenant boundary: it
                // comes back null both for unconfined STAFF and for a tenant
                // with no site-scoped overlay. The client_id check is what
                // actually keeps one tenant off another's failures.
                if ($allowed !== null) {
                    $q->whereIn('project_id', $allowed);
                }
                if ($tenantId !== null) {
                    $q->whereHas('project', fn ($p) => $p->where('client_id', $tenantId));
                }
            })
            ->with(['package', 'computer.project.client'])
            ->latest('id')
            ->limit(self::MAX_SCANNED)
            ->get();

        return $failures
            ->groupBy(fn (DeploymentJob $job) => $job->causeKey())
            ->map(fn (Collection $group) => $this->summarise($group))
            ->reject(fn (array $cause) => $this->isResolved($cause))
            ->sortByDesc(fn (array $cause) => $cause['last_seen'])
            ->values();
    }

    /** @param  Collection<int, DeploymentJob>  $group */
    private function summarise(Collection $group): array
    {
        /** @var DeploymentJob $latest */
        $latest = $group->sortByDesc('id')->first();
        $kind = $latest->failureKind();

        return [
            'cause_key'          => $latest->causeKey(),
            'kind'               => $kind,
            'owner'              => $kind->ownedByOperator()
                ? 'Platform administrator'
                : 'Whoever manages this machine',
            'package'            => $latest->package,
            // Only a machine-scoped cause names one machine; a package cause
            // may span several, so naming a single one here would mislead.
            'computer'           => $kind === \App\Enums\FailureKind::Machine ? $latest->computer : null,
            'exit_code'          => $latest->exit_code,
            'failure_reason'     => $latest->failure_reason,
            'hint'               => $latest->failureHint(),
            'affected_computers' => $group->pluck('computer_id')->unique()->count(),
            'first_seen'         => $group->min('created_at'),
            'last_seen'          => $group->max(fn (DeploymentJob $j) => $j->finished_at ?? $j->created_at),
            'latest_job'         => $latest,
        ];
    }

    private function isResolved(array $cause): bool
    {
        return $this->clearedBySuccess($cause) || $this->dismissed($cause);
    }

    /** Did the same scope succeed after this failure was recorded? */
    private function clearedBySuccess(array $cause): bool
    {
        /** @var DeploymentJob $latest */
        $latest = $cause['latest_job'];
        $boundary = $latest->finished_at ?? $latest->created_at;

        $scoped = $cause['kind'] === \App\Enums\FailureKind::Machine
            ? DeploymentJob::where('computer_id', $latest->computer_id)
            : DeploymentJob::where('package_id', $latest->package_id);

        return $scoped->where('status', JobStatus::Succeeded)
            ->where('finished_at', '>', $boundary)
            ->exists();
    }

    private function dismissed(array $cause): bool
    {
        $dismissal = DeploymentFailureDismissal::where('cause_key', $cause['cause_key'])->first();

        return $dismissal !== null && $dismissal->last_seen_job_id >= $cause['latest_job']->id;
    }

    /**
     * An operator has looked at this and dealt with it.
     *
     * Dismissal is stored once per cause, not once per viewer, so who may
     * dismiss matters more than it would for a purely personal "mark read":
     * silencing a PACKAGE cause hides it from every tenant it affects, not
     * just the person who clicked. Package/unknown causes are therefore
     * staff-only; a machine cause may be cleared by whoever manages that
     * one machine, matching the ownership already stated on the queue.
     */
    public function markHandled(string $causeKey, DeploymentJob $latestJob, User $by): void
    {
        $kind = $latestJob->failureKind();

        if ($kind->ownedByOperator()) {
            abort_unless($by->tenantClientId() === null, 403, 'Only platform staff can dismiss a package-level failure.');
        } else {
            abort_unless(
                $by->tenantClientId() === null || $by->tenantClientId() === $latestJob->computer->project->client_id,
                403
            );
        }

        DeploymentFailureDismissal::updateOrCreate(
            ['cause_key' => $causeKey],
            ['last_seen_job_id' => $latestJob->id, 'dismissed_by' => $by->id, 'dismissed_at' => now()]
        );
    }
}
