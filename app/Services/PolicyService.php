<?php

namespace App\Services;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\SoftwarePolicy;

/**
 * Desired-state enforcement: compares each policy against the fleet's
 * reported software inventory and queues only the deployment jobs needed
 * to close the gap. Idempotent — machines already compliant, or with a
 * job already in flight, are skipped.
 */
class PolicyService
{
    /** Updates re-queue at most once per this window (scheduler refines this). */
    private const UPDATE_COOLDOWN_HOURS = 23;

    public function __construct(
        private readonly DeploymentService $deployments,
    ) {
    }

    /**
     * Enforce one policy across its project. Returns the number of jobs queued.
     */
    public function enforce(SoftwarePolicy $policy): int
    {
        if (! $policy->is_active || ! $policy->package->is_active) {
            return 0;
        }

        $queued = 0;
        foreach ($policy->project->computers()->get() as $computer) {
            if ($this->enforceOn($policy, $computer)) {
                $queued++;
            }
        }

        $policy->forceFill(['last_enforced_at' => now()])->saveQuietly();

        return $queued;
    }

    /**
     * Enforce every active policy of the computer's project against one
     * machine — called whenever an agent reports software inventory, so
     * new machines self-provision and drift heals automatically.
     */
    public function enforceForComputer(Computer $computer): int
    {
        $queued = 0;

        $policies = SoftwarePolicy::with('package')
            ->where('project_id', $computer->project_id)
            ->where('is_active', true)
            ->get();

        foreach ($policies as $policy) {
            if ($policy->package->is_active && $this->enforceOn($policy, $computer)) {
                $queued++;
            }
        }

        return $queued;
    }

    private function enforceOn(SoftwarePolicy $policy, Computer $computer): bool
    {
        if (! $this->needsAction($policy, $computer)) {
            return false;
        }

        if ($this->hasRelevantJob($policy, $computer)) {
            return false;
        }

        $this->deployments->queue(
            computer: $computer,
            package: $policy->package,
            action: $policy->action,
            priority: $policy->priority,
            createdBy: $policy->created_by,
        );

        return true;
    }

    private function needsAction(SoftwarePolicy $policy, Computer $computer): bool
    {
        $installed = $this->isInstalledOn($policy->package, $computer);

        return match ($policy->action) {
            JobAction::Install => ! $installed,
            JobAction::Update, JobAction::Uninstall => $installed,
            default => false,
        };
    }

    /**
     * Installed-state detection: package-manager packages match the
     * inventory exactly by id; binary packages fall back to a
     * successful-install job (registry names are not reliably matchable).
     */
    public function isInstalledOn(Package $package, Computer $computer): bool
    {
        if ($package->installer_type->requiresPackageManagerId()) {
            $id = $package->winget_id ?? $package->choco_id;
            $source = $package->winget_id !== null ? 'winget' : 'choco';

            return $computer->software()
                ->where('source', $source)
                ->where('name', $id)
                ->exists();
        }

        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $package->id)
            ->whereIn('action', [JobAction::Install, JobAction::Update])
            ->where('status', JobStatus::Succeeded)
            ->exists();
    }

    /**
     * A job that makes queueing another one pointless right now: any
     * non-terminal job for the same package, or (for updates) a recent
     * successful update inside the cooldown window.
     */
    private function hasRelevantJob(SoftwarePolicy $policy, Computer $computer): bool
    {
        $inFlight = DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])
            ->exists();

        if ($inFlight) {
            return true;
        }

        if ($policy->action === JobAction::Update) {
            return DeploymentJob::where('computer_id', $computer->id)
                ->where('package_id', $policy->package_id)
                ->where('action', JobAction::Update)
                ->where('status', JobStatus::Succeeded)
                ->where('finished_at', '>=', now()->subHours(self::UPDATE_COOLDOWN_HOURS))
                ->exists();
        }

        // Failed jobs are terminal: the policy will not retry them
        // automatically — an operator retries from the deployments page.
        if ($policy->action === JobAction::Uninstall || $policy->action === JobAction::Install) {
            return DeploymentJob::where('computer_id', $computer->id)
                ->where('package_id', $policy->package_id)
                ->where('action', $policy->action)
                ->where('status', JobStatus::Failed)
                ->where('finished_at', '>=', now()->subHours(self::UPDATE_COOLDOWN_HOURS))
                ->exists();
        }

        return false;
    }
}
