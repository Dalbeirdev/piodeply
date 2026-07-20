<?php

namespace App\Livewire\Policies;

use App\Enums\PolicyAction;
use App\Enums\PolicyMode;
use App\Enums\PolicyVersionMode;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use Illuminate\Validation\Rule;
use Livewire\Component;

class PolicyForm extends Component
{
    public ?SoftwarePolicy $policy = null;

    public ?int $project_id = null;

    public ?int $package_id = null;

    public string $action = 'install';

    public string $mode = 'enforce';

    public string $version_mode = 'latest';

    public ?string $desired_version = null;

    public int $priority = 5;

    public string $frequency = 'daily';

    /** @var list<int> ISO weekdays 1 (Mon) – 7 (Sun); empty = anytime */
    public array $window_days = [];

    public ?string $window_start = null;

    public ?string $window_end = null;

    public int $test_delay_days = 0;

    public int $production_delay_days = 0;

    public function mount(?SoftwarePolicy $policy = null): void
    {
        if ($policy !== null && $policy->exists) {
            $this->authorize('update', $policy);
            $this->policy = $policy;
            $this->project_id = $policy->project_id;
            $this->package_id = $policy->package_id;
            $this->action = $policy->action->value;
            $this->mode = $policy->mode->value;
            $this->version_mode = $policy->version_mode->value;
            $this->desired_version = $policy->desired_version;
            $this->priority = $policy->priority;
            $this->frequency = $policy->frequency?->value ?? 'daily';
            $this->window_days = $policy->window_days ?? [];
            $this->window_start = $policy->window_start ? substr($policy->window_start, 0, 5) : null;
            $this->window_end = $policy->window_end ? substr($policy->window_end, 0, 5) : null;
            $this->test_delay_days = $policy->test_delay_days ?? 0;
            $this->production_delay_days = $policy->production_delay_days ?? 0;
        } else {
            $this->authorize('create', SoftwarePolicy::class);
        }
    }

    public function save()
    {
        $this->authorize($this->policy ? 'update' : 'create', $this->policy ?? SoftwarePolicy::class);

        $validated = $this->validate([
            'project_id'      => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'package_id'      => ['required', 'integer', Rule::exists('packages', 'id')->withoutTrashed()],
            'action'          => ['required', Rule::in(PolicyAction::values())],
            'mode'            => ['required', Rule::in(PolicyMode::values())],
            'version_mode'    => ['required', Rule::in(PolicyVersionMode::values())],
            'desired_version' => ['nullable', 'string', 'max:100', 'regex:/^[0-9][0-9A-Za-z.\-+]*$/'],
            'priority'        => ['required', 'integer', 'between:1,10'],
            'frequency'       => ['required', Rule::in(\App\Enums\PolicyFrequency::values())],
            'window_days'     => ['array'],
            'window_days.*'   => ['integer', 'between:1,7'],
            'window_start'    => ['nullable', 'date_format:H:i', 'required_with:window_end'],
            'window_end'      => ['nullable', 'date_format:H:i', 'required_with:window_start'],
            'test_delay_days'       => ['required', 'integer', 'between:0,365'],
            'production_delay_days' => ['required', 'integer', 'between:0,365'],
        ], [
            'desired_version.regex' => 'Versions look like 24.09 or 139.0.7258.67.',
        ], [
            'project_id'      => 'project',
            'package_id'      => 'package',
            'desired_version' => 'version',
            'window_start'    => 'window start',
            'window_end'      => 'window end',
        ]);

        // A window needs both days and times; days without times (or vice
        // versa) is half a window.
        if ($validated['window_days'] !== [] && ($validated['window_start'] === null || $validated['window_end'] === null)) {
            $this->addError('window_start', 'Pick a start and end time for the maintenance window.');

            return null;
        }
        if ($validated['window_days'] === []) {
            $validated['window_start'] = null;
            $validated['window_end'] = null;
            $validated['window_days'] = null;
        } else {
            $validated['window_days'] = array_values(array_map('intval', $validated['window_days']));
        }

        $versionMode = PolicyVersionMode::from($validated['version_mode']);
        $action = PolicyAction::from($validated['action']);
        $package = Package::findOrFail($validated['package_id']);

        // A private package only ever governs its own client's projects —
        // the deploy funnel enforces this too, but failing here is a form
        // error instead of a queued job that can never run.
        $targetProject = \App\Models\Project::findOrFail($validated['project_id']);
        if (! $package->isUsableFor($targetProject)) {
            $this->addError('package_id', "\"{$package->name}\" is private to another client and cannot be used for this project.");

            return null;
        }

        // Pinning needs a version; Latest ignores one.
        if (($versionMode->requiresVersion() || $action === PolicyAction::ForceUpdate)
            && blank($validated['desired_version'])) {
            $this->addError('desired_version', 'This policy needs a version.');

            return null;
        }
        if (! $versionMode->requiresVersion()) {
            $validated['desired_version'] = $action === PolicyAction::ForceUpdate
                ? $validated['desired_version'] : null;
        }

        // Version pinning and force update run `winget install --version` —
        // only winget packages support it.
        if (($versionMode->requiresVersion() || $action === PolicyAction::ForceUpdate)
            && $package->winget_id === null) {
            $this->addError('version_mode', 'Version pinning is only available for winget packages.');

            return null;
        }

        // Removal policies have no version dimension.
        if (in_array($action, [PolicyAction::Uninstall, PolicyAction::Block], true)) {
            $validated['version_mode'] = PolicyVersionMode::Latest->value;
            $validated['desired_version'] = null;
        }

        // One rule per project+package+action — duplicates would double-queue.
        $duplicate = SoftwarePolicy::where('project_id', $validated['project_id'])
            ->where('package_id', $validated['package_id'])
            ->where('action', $validated['action'])
            ->when($this->policy, fn ($q) => $q->whereKeyNot($this->policy->id))
            ->exists();

        if ($duplicate) {
            $this->addError('package_id', 'This project already has that policy for this package.');

            return null;
        }

        if ($this->policy) {
            // A new desired version is a new rollout — rings restage from now.
            if ($validated['desired_version'] !== $this->policy->desired_version) {
                $validated['rollout_started_at'] = now();
            }
            $this->policy->update($validated);
            session()->flash('status', 'Policy saved.');
        } else {
            SoftwarePolicy::create($validated + [
                'created_by'         => auth()->id(),
                'rollout_started_at' => now(),
            ]);
            session()->flash('status', 'Policy created. It applies automatically as agents report in — or use “Enforce now”.');
        }

        return $this->redirectRoute('policies.index');
    }

    public function render()
    {
        return view('livewire.policies.policy-form', [
            'projects'     => Project::orderBy('name')->get(['id', 'name']),
            'packages'     => Package::active()->visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'installer_type']),
            'actions'      => PolicyAction::cases(),
            'modes'        => PolicyMode::cases(),
            'versionModes' => PolicyVersionMode::cases(),
            'priorities'   => SoftwarePolicy::PRIORITIES,
            'frequencies'  => \App\Enums\PolicyFrequency::cases(),
            'weekdays'     => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'],
        ])->layout('layouts.app');
    }
}
