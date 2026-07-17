<?php

namespace App\Livewire\Deployments;

use App\Enums\JobAction;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Services\DeploymentService;
use App\Services\InstalledStateService;
use App\Services\WingetVersionService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Queue a deployment against a single computer (from its detail page).
 */
class DeployToComputer extends Component
{
    public Computer $computer;

    public ?int $package_id = null;

    public string $action = 'install';

    public int $priority = 5;

    /** Optional pinned version (winget/choco `--version`). */
    public ?string $target_version = null;

    /** Deploy anyway when the machine already satisfies the request. */
    public bool $force = false;

    public function mount(Computer $computer): void
    {
        $this->computer = $computer;
    }

    /**
     * The action deliberately does not follow the installed state. Flipping
     * to Update when a package is present would invite a job winget answers
     * with "no applicable upgrade" — the same pointless row this set of
     * changes exists to remove. Install stays the default; the button says
     * when it would do nothing.
     */
    private function targetVersion(): ?string
    {
        return $this->target_version !== null && trim($this->target_version) !== ''
            ? trim($this->target_version)
            : null;
    }

    public function queue(DeploymentService $service): void
    {
        $this->authorize('create', DeploymentJob::class);

        $validated = $this->validate([
            'package_id'     => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)],
            'action'         => ['required', Rule::in(JobAction::values())],
            'priority'       => ['required', 'integer', 'between:1,10'],
            // A rollback with no version is not a job anything can carry out.
            'target_version' => [
                Rule::requiredIf(fn () => $this->action === JobAction::Rollback->value),
                'nullable', 'string', 'max:100',
            ],
        ], [
            'target_version.required' => 'Pin the version to roll back to.',
        ]);

        $result = $service->queueIfNeeded(
            computer: $this->computer,
            package: Package::findOrFail($validated['package_id']),
            action: JobAction::from($validated['action']),
            priority: $validated['priority'],
            createdBy: auth()->id(),
            targetVersion: $this->targetVersion(),
            force: $this->force,
        );

        $this->reset(['package_id', 'target_version', 'force']);
        $this->dispatch('job-queued');
        session()->flash('status', $result->message);
    }

    public function render(InstalledStateService $installedState, WingetVersionService $wingetVersions)
    {
        $package = $this->package_id !== null
            ? Package::active()->find($this->package_id)
            : null;

        $action = JobAction::tryFrom($this->action);
        $state = $package !== null ? $installedState->stateOf($package, $this->computer) : null;

        $satisfied = $state !== null && $action !== null
            && $installedState->isSatisfiedBy($state, $action, $this->targetVersion());

        $needsVersion = $action === JobAction::Rollback && $this->targetVersion() === null;

        return view('livewire.deployments.deploy-to-computer', [
            'packages'  => Package::active()->orderBy('name')->get(['id', 'name', 'installer_type']),
            'actions'   => JobAction::cases(),
            'package'   => $package,
            'state'     => $state,
            'satisfied' => $satisfied,
            'canQueue'  => (! $satisfied || $this->force) && ! $needsVersion,
            'label'     => $this->buttonLabel($package, $state, $action, $satisfied),
            // Only package managers report a version we can trust.
            'versionKnown' => $package?->installer_type->requiresPackageManagerId() ?? false,
            // Null means we could not find out, which the form must not
            // present as "no versions exist" — it falls back to free text.
            'offeredVersions' => $package !== null ? $wingetVersions->versionsFor($package) : null,
        ]);
    }

    /**
     * The button states the outcome before it is clicked, rather than
     * queueing first and reporting "nothing to do" afterwards.
     *
     * @param  array{present: bool, version: ?string}|null  $state
     */
    private function buttonLabel(?Package $package, ?array $state, ?JobAction $action, bool $satisfied): string
    {
        if ($package === null || $state === null || $action === null) {
            return 'Queue';
        }

        // Say what is missing, rather than let it queue and fail three times.
        if ($action === JobAction::Rollback && $this->targetVersion() === null) {
            return 'Pin a version to roll back to';
        }

        if ($satisfied && ! $this->force) {
            return $action === JobAction::Uninstall ? 'Not installed' : 'Up to date';
        }

        $from = $state['version'];
        $to = $this->targetVersion();

        return match (true) {
            $action === JobAction::Install && $state['present'] && $from !== null && $to !== null
                => "Upgrade {$from} → {$to}",
            $action === JobAction::Install && $state['present'] => 'Reinstall',
            $action === JobAction::Update && $from !== null => "Update from {$from}",
            default => $action->label(),
        };
    }
}
