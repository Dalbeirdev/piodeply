<?php

namespace App\Services;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\PolicyAction;
use App\Enums\PolicyVersionMode;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\SoftwarePolicy;
use Illuminate\Support\Collection;

/**
 * Desired-state enforcement and compliance. Each policy is compared
 * against the fleet's reported software inventory; enforcement queues
 * only the remediation jobs needed to close the gap, and compliance
 * reports the same comparison without touching anything (Audit mode).
 */
class PolicyService
{
    /** Routine updates / failed attempts re-queue at most once per window. */
    private const COOLDOWN_HOURS = 23;

    public function __construct(
        private readonly DeploymentService $deployments,
    ) {
    }

    /* ─────────────────────────── Enforcement ─────────────────────────── */

    /** Enforce one policy across its project. Returns jobs queued. */
    public function enforce(SoftwarePolicy $policy): int
    {
        if (! $policy->isEnforceable()) {
            return 0;
        }

        $excluded = $policy->excludedComputers()->pluck('computers.id')->all();

        $queued = 0;
        foreach ($policy->project->computers()->whereNotIn('id', $excluded)->get() as $computer) {
            if ($this->enforceOn($policy, $computer)) {
                $queued++;
            }
        }

        $policy->forceFill(['last_enforced_at' => now()])->saveQuietly();

        return $queued;
    }

    /**
     * Enforce every enforceable policy of the computer's project against
     * one machine — runs on every agent software report, so new machines
     * self-provision and drift heals automatically.
     */
    public function enforceForComputer(Computer $computer): int
    {
        $queued = 0;

        $policies = SoftwarePolicy::with('package')
            ->where('project_id', $computer->project_id)
            ->where('mode', \App\Enums\PolicyMode::Enforce)
            ->get();

        foreach ($policies as $policy) {
            if (! $policy->package->is_active) {
                continue;
            }
            if ($policy->excludedComputers()->whereKey($computer->id)->exists()) {
                continue;
            }
            if ($this->enforceOn($policy, $computer)) {
                $queued++;
            }
        }

        return $queued;
    }

    private function enforceOn(SoftwarePolicy $policy, Computer $computer): bool
    {
        $remediation = $this->remediationFor($policy, $computer);

        if ($remediation === null || $this->hasRelevantJob($policy, $computer)) {
            return false;
        }

        $this->deployments->queue(
            computer: $computer,
            package: $policy->package,
            action: $remediation['action'],
            priority: $policy->priority,
            createdBy: $policy->created_by,
            targetVersion: $remediation['version'],
        );

        return true;
    }

    /* ─────────────────────────── Desired state ───────────────────────── */

    /**
     * The job (if any) that would bring this computer to the policy's
     * desired state — or null when already compliant / not applicable.
     *
     * @return array{action: JobAction, version: ?string}|null
     */
    public function remediationFor(SoftwarePolicy $policy, Computer $computer): ?array
    {
        $state = $this->installedStateOn($policy->package, $computer);

        return match ($policy->action) {
            PolicyAction::Install => $state['present']
                ? $this->versionRemediation($policy, $state['version'])
                : [
                    'action'  => JobAction::Install,
                    'version' => $policy->version_mode === PolicyVersionMode::Exact ? $policy->desired_version : null,
                ],

            PolicyAction::Update => ! $state['present'] ? null : ($policy->version_mode === PolicyVersionMode::Latest
                ? ['action' => JobAction::Update, 'version' => null] // routine; cooldown sets the cadence
                : $this->versionRemediation($policy, $state['version'])),

            PolicyAction::ForceUpdate => $state['present'] && $policy->desired_version !== null
                ? ['action' => JobAction::Rollback, 'version' => $policy->desired_version]
                : null,

            PolicyAction::Uninstall, PolicyAction::Block => $state['present']
                ? ['action' => JobAction::Uninstall, 'version' => null]
                : null,
        };
    }

    /** @return array{action: JobAction, version: ?string}|null */
    private function versionRemediation(SoftwarePolicy $policy, ?string $installedVersion): ?array
    {
        if ($this->versionSatisfied($policy, $installedVersion)) {
            return null;
        }

        return match ($policy->version_mode) {
            // Exact and Freeze can mean a downgrade — the agent runs
            // rollback as `winget install --version X --force`.
            PolicyVersionMode::Exact,
            PolicyVersionMode::Maximum => ['action' => JobAction::Rollback, 'version' => $policy->desired_version],
            PolicyVersionMode::Minimum => ['action' => JobAction::Update, 'version' => null],
            PolicyVersionMode::Latest => null,
        };
    }

    public function versionSatisfied(SoftwarePolicy $policy, ?string $installedVersion): bool
    {
        if ($policy->version_mode === PolicyVersionMode::Latest || $policy->desired_version === null) {
            return true;
        }

        // Version pinning only works where the inventory reports versions
        // we can trust (package managers); binary packages pass.
        if (! $policy->package->installer_type->requiresPackageManagerId()) {
            return true;
        }

        if ($installedVersion === null) {
            return false; // present but version unknown → cannot verify
        }

        return match ($policy->version_mode) {
            PolicyVersionMode::Exact => version_compare($installedVersion, $policy->desired_version, '=='),
            PolicyVersionMode::Minimum => version_compare($installedVersion, $policy->desired_version, '>='),
            PolicyVersionMode::Maximum => version_compare($installedVersion, $policy->desired_version, '<='),
            PolicyVersionMode::Latest => true,
        };
    }

    /**
     * Installed-state detection: package-manager packages match the
     * inventory exactly by id (and carry a version); binary packages fall
     * back to a successful-install job (version unknowable).
     *
     * @return array{present: bool, version: ?string}
     */
    public function installedStateOn(Package $package, Computer $computer): array
    {
        if ($package->installer_type->requiresPackageManagerId()) {
            $id = $package->winget_id ?? $package->choco_id;
            $source = $package->winget_id !== null ? 'winget' : 'choco';

            $row = $computer->software()
                ->where('source', $source)
                ->where('name', $id)
                ->first();

            return ['present' => $row !== null, 'version' => $row?->version];
        }

        $present = DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $package->id)
            ->whereIn('action', [JobAction::Install, JobAction::Update])
            ->where('status', JobStatus::Succeeded)
            ->exists();

        return ['present' => $present, 'version' => null];
    }

    /* ─────────────────────────── Compliance ──────────────────────────── */

    /**
     * Per-computer compliance rows for a policy.
     *
     * @return Collection<int, array{computer: Computer, status: string, offline: bool, installed_version: ?string, reason: string}>
     */
    public function complianceFor(SoftwarePolicy $policy): Collection
    {
        $excluded = $policy->excludedComputers()->pluck('computers.id')->flip();

        return $policy->project->computers()->orderBy('hostname')->get()
            ->map(function (Computer $computer) use ($policy, $excluded) {
                $state = $this->installedStateOn($policy->package, $computer);

                if ($excluded->has($computer->id)) {
                    return $this->row($computer, 'excluded', $state['version'], 'Excluded from this policy');
                }

                $remediation = $this->remediationFor($policy, $computer);

                if ($remediation === null) {
                    return $this->row($computer, 'compliant', $state['version'], $this->compliantReason($policy, $state));
                }

                // Routine latest-updates: a recent success means "current
                // as of the last run", not drift.
                if ($policy->action === PolicyAction::Update
                    && $policy->version_mode === PolicyVersionMode::Latest
                    && $this->hasRecentSuccess($policy, $computer, JobAction::Update)) {
                    return $this->row($computer, 'compliant', $state['version'], 'Updated within the last day');
                }

                if ($this->hasJobInFlight($policy, $computer)) {
                    return $this->row($computer, 'pending', $state['version'], 'Remediation job queued or running');
                }

                if ($this->lastAttemptFailed($policy, $computer)) {
                    return $this->row($computer, 'failed', $state['version'], 'Last remediation attempt failed');
                }

                return $this->row($computer, 'non_compliant', $state['version'], $this->driftReason($policy, $state));
            });
    }

    /**
     * @return array{target: int, compliant: int, pending: int, failed: int, non_compliant: int, excluded: int, offline: int, percent: ?float}
     */
    public function complianceSummary(SoftwarePolicy $policy): array
    {
        $rows = $this->complianceFor($policy);

        $counts = [
            'target'        => $rows->where('status', '!=', 'excluded')->count(),
            'compliant'     => $rows->where('status', 'compliant')->count(),
            'pending'       => $rows->where('status', 'pending')->count(),
            'failed'        => $rows->where('status', 'failed')->count(),
            'non_compliant' => $rows->where('status', 'non_compliant')->count(),
            'excluded'      => $rows->where('status', 'excluded')->count(),
            'offline'       => $rows->where('offline', true)->where('status', '!=', 'excluded')->count(),
        ];

        $counts['percent'] = $counts['target'] > 0
            ? round($counts['compliant'] / $counts['target'] * 100, 1)
            : null;

        return $counts;
    }

    /** @return array{computer: Computer, status: string, offline: bool, installed_version: ?string, reason: string} */
    private function row(Computer $computer, string $status, ?string $version, string $reason): array
    {
        return [
            'computer'          => $computer,
            'status'            => $status,
            'offline'           => ! $computer->isOnline(),
            'installed_version' => $version,
            'reason'            => $reason,
        ];
    }

    /** @param array{present: bool, version: ?string} $state */
    private function compliantReason(SoftwarePolicy $policy, array $state): string
    {
        return match ($policy->action) {
            PolicyAction::Uninstall, PolicyAction::Block => 'Not installed',
            PolicyAction::Update, PolicyAction::ForceUpdate => $state['present'] ? 'Version satisfies the policy' : 'Not installed — not targeted',
            PolicyAction::Install => 'Installed' . ($state['version'] !== null ? " ({$state['version']})" : ''),
        };
    }

    /** @param array{present: bool, version: ?string} $state */
    private function driftReason(SoftwarePolicy $policy, array $state): string
    {
        if (! $state['present']) {
            return 'Not installed';
        }

        if ($policy->version_mode->requiresVersion() && ! $this->versionSatisfied($policy, $state['version'])) {
            $installed = $state['version'] ?? 'unknown version';

            return "Installed {$installed}, policy wants {$policy->version_mode->label()} {$policy->desired_version}";
        }

        return match ($policy->action) {
            PolicyAction::Uninstall => 'Installed — should be removed',
            PolicyAction::Block => 'Blocked software detected',
            PolicyAction::Update => 'Update due',
            PolicyAction::ForceUpdate => 'Reinstall due',
            default => 'Out of desired state',
        };
    }

    /* ─────────────────────────── Job guards ──────────────────────────── */

    /** Anything that makes queueing another job pointless right now. */
    private function hasRelevantJob(SoftwarePolicy $policy, Computer $computer): bool
    {
        if ($this->hasJobInFlight($policy, $computer)) {
            return true;
        }

        // Routine latest-updates: at most one per cooldown window.
        if ($policy->action === PolicyAction::Update
            && $policy->version_mode === PolicyVersionMode::Latest
            && $this->hasRecentSuccess($policy, $computer, JobAction::Update)) {
            return true;
        }

        // Force update: at most one reinstall per cooldown window.
        if ($policy->action === PolicyAction::ForceUpdate
            && $this->hasRecentSuccess($policy, $computer, JobAction::Rollback)) {
            return true;
        }

        // Failed attempts are not hammered — an operator retries sooner
        // from the deployments page if needed.
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->where('status', JobStatus::Failed)
            ->where('finished_at', '>=', now()->subHours(self::COOLDOWN_HOURS))
            ->exists();
    }

    private function hasJobInFlight(SoftwarePolicy $policy, Computer $computer): bool
    {
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->whereIn('status', [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running])
            ->exists();
    }

    private function hasRecentSuccess(SoftwarePolicy $policy, Computer $computer, JobAction $action): bool
    {
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->where('action', $action)
            ->where('status', JobStatus::Succeeded)
            ->where('finished_at', '>=', now()->subHours(self::COOLDOWN_HOURS))
            ->exists();
    }

    private function lastAttemptFailed(SoftwarePolicy $policy, Computer $computer): bool
    {
        return DeploymentJob::where('computer_id', $computer->id)
            ->where('package_id', $policy->package_id)
            ->where('status', JobStatus::Failed)
            ->where('finished_at', '>=', now()->subHours(self::COOLDOWN_HOURS))
            ->exists();
    }
}
