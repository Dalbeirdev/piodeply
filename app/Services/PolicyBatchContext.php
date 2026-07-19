<?php

namespace App\Services;

use App\Enums\InstallerType;
use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\SoftwarePolicy;
use Illuminate\Support\Collection;

/**
 * Per-policy prefetch for fleet-wide enforcement. The per-computer path asks
 * the database 15-20 questions per machine; across a 1,000-device project
 * that is ~20,000 queries per enforcement pass. This context answers the
 * same questions from three set-based queries, so the pass scales with the
 * number of policies, not devices × policies.
 *
 * The answers must match InstalledStateService / PolicyService's live
 * queries exactly — every rule here mirrors one there, and the enforcement
 * tests run the same scenarios through both paths.
 */
final class PolicyBatchContext
{
    /** @var array<int, string|null> computer_id → installed version (row present) */
    private array $versions = [];

    /** @var array<int, true> computer_ids where the manager scan reports anything */
    private array $scanWorks = [];

    /** @var array<int, Collection<int, object>> computer_id → job rows (action, status, finished_at) */
    private array $jobs = [];

    private function __construct(
        private readonly ?string $managerSource,
        private readonly bool $hasManagerId,
    ) {
    }

    /** @param Collection<int, Computer> $computers */
    public static function for(SoftwarePolicy $policy, Collection $computers): self
    {
        $package = $policy->package;

        $source = match ($package->installer_type) {
            InstallerType::Winget => 'winget',
            InstallerType::Choco => 'choco',
            default => null,
        };
        $id = $source === 'winget' ? $package->winget_id : ($source === 'choco' ? $package->choco_id : null);

        $context = new self($source, $id !== null);
        $ids = $computers->modelKeys();

        if ($ids === []) {
            return $context;
        }

        if ($source !== null && $id !== null) {
            $context->versions = ComputerSoftware::query()
                ->whereIn('computer_id', $ids)
                ->where('source', $source)
                ->where('name', $id)
                ->pluck('version', 'computer_id')
                ->all();

            $context->scanWorks = ComputerSoftware::query()
                ->whereIn('computer_id', $ids)
                ->where('source', $source)
                ->distinct()
                ->pluck('computer_id')
                ->flip()
                ->map(fn () => true)
                ->all();
        }

        $context->jobs = DeploymentJob::query()
            ->whereIn('computer_id', $ids)
            ->where('package_id', $package->id)
            ->get(['computer_id', 'action', 'status', 'finished_at'])
            ->groupBy('computer_id')
            ->all();

        return $context;
    }

    /**
     * Mirrors InstalledStateService::stateOf() decision for decision.
     *
     * @return array{present: bool, version: ?string}
     */
    public function stateOf(Computer $computer): array
    {
        if ($this->managerSource !== null && $this->hasManagerId) {
            if (array_key_exists($computer->id, $this->versions)) {
                return ['present' => true, 'version' => $this->versions[$computer->id]];
            }

            if (isset($this->scanWorks[$computer->id])) {
                return ['present' => false, 'version' => null]; // scan works → absence is real
            }
        }

        // Blind scan, binary package, or manager id missing: our job history.
        return ['present' => $this->everInstalled($computer), 'version' => null];
    }

    public function hasJobInFlight(Computer $computer): bool
    {
        return $this->jobsFor($computer)->contains(fn (object $job) => in_array(
            $job->status,
            [JobStatus::Pending, JobStatus::Blocked, JobStatus::Running],
            true,
        ));
    }

    public function hasRecentSuccess(Computer $computer, JobAction $action, int $cooldownHours): bool
    {
        $cutoff = now()->subHours($cooldownHours);

        return $this->jobsFor($computer)->contains(fn (object $job) => $job->action === $action
            && $job->status === JobStatus::Succeeded
            && $job->finished_at !== null
            && $job->finished_at->gte($cutoff));
    }

    public function failedRecently(Computer $computer, int $backoffHours): bool
    {
        $cutoff = now()->subHours($backoffHours);

        return $this->jobsFor($computer)->contains(fn (object $job) => in_array($job->status, [JobStatus::Failed, JobStatus::Cancelled], true)
            && $job->finished_at !== null
            && $job->finished_at->gte($cutoff));
    }

    private function everInstalled(Computer $computer): bool
    {
        return $this->jobsFor($computer)->contains(fn (object $job) => in_array($job->action, [JobAction::Install, JobAction::Update], true)
            && $job->status === JobStatus::Succeeded);
    }

    /** @return Collection<int, object> */
    private function jobsFor(Computer $computer): Collection
    {
        return $this->jobs[$computer->id] ?? collect();
    }
}
