<?php

namespace App\Services;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;

/**
 * Answers "is this package on this computer, and at what version?".
 *
 * Kept apart from PolicyService so the deployment queue can ask the same
 * question without a circular dependency (PolicyService already depends on
 * DeploymentService to queue its remediations).
 */
class InstalledStateService
{
    /**
     * Package-manager packages match the inventory exactly by id and carry
     * a trustworthy version; binary packages fall back to a successful
     * install job, where the version is unknowable.
     *
     * @return array{present: bool, version: ?string}
     */
    public function stateOf(Package $package, Computer $computer): array
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

    /**
     * Whether queueing $action would change anything, given what is already
     * installed. Only Install and Uninstall are decidable: an Update or
     * Rollback depends on what the package source offers, which only the
     * agent can resolve.
     *
     * @param  array{present: bool, version: ?string}  $state
     */
    public function isSatisfiedBy(array $state, JobAction $action, ?string $targetVersion): bool
    {
        return match ($action) {
            JobAction::Install => $state['present'] && $this->versionMeets($state['version'], $targetVersion),
            JobAction::Uninstall => ! $state['present'],
            default => false,
        };
    }

    /**
     * No target pinned means "just be present". A pinned target with an
     * unknown installed version cannot be verified, so we let the job run
     * rather than wrongly skip it.
     */
    private function versionMeets(?string $installedVersion, ?string $targetVersion): bool
    {
        if ($targetVersion === null) {
            return true;
        }

        if ($installedVersion === null) {
            return false;
        }

        return version_compare($installedVersion, $targetVersion, '>=');
    }
}
