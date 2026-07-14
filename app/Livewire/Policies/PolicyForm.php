<?php

namespace App\Livewire\Policies;

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

    public int $priority = 5;

    public bool $is_active = true;

    public function mount(?SoftwarePolicy $policy = null): void
    {
        if ($policy !== null && $policy->exists) {
            $this->authorize('update', $policy);
            $this->policy = $policy;
            $this->project_id = $policy->project_id;
            $this->package_id = $policy->package_id;
            $this->action = $policy->action->value;
            $this->priority = $policy->priority;
            $this->is_active = $policy->is_active;
        } else {
            $this->authorize('create', SoftwarePolicy::class);
        }
    }

    public function save()
    {
        $this->authorize($this->policy ? 'update' : 'create', $this->policy ?? SoftwarePolicy::class);

        $validated = $this->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->withoutTrashed()],
            'action'     => ['required', Rule::in(SoftwarePolicy::ACTIONS)],
            'priority'   => ['required', 'integer', 'between:1,10'],
            'is_active'  => ['boolean'],
        ], [], [
            'project_id' => 'project',
            'package_id' => 'package',
        ]);

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
            $this->policy->update($validated);
            session()->flash('status', 'Policy saved.');
        } else {
            SoftwarePolicy::create($validated + ['created_by' => auth()->id()]);
            session()->flash('status', 'Policy created. It applies automatically as agents report in — or use “Enforce now”.');
        }

        return $this->redirectRoute('policies.index');
    }

    public function render()
    {
        return view('livewire.policies.policy-form', [
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'packages' => Package::active()->orderBy('name')->get(['id', 'name', 'installer_type']),
        ])->layout('layouts.app');
    }
}
