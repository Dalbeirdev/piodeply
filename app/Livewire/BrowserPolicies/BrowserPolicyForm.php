<?php

namespace App\Livewire\BrowserPolicies;

use App\Enums\Browser;
use App\Enums\BrowserPolicyType;
use App\Models\BrowserPolicy;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Component;

class BrowserPolicyForm extends Component
{
    public ?BrowserPolicy $policy = null;

    public string $name = '';

    public ?int $project_id = null;

    public string $type = 'disable_incognito';

    /** @var list<string> 'all' or Browser values */
    public array $browsers = ['all'];

    public string $action = 'disable';

    public string $status = 'active';

    public ?string $description = null;

    public function mount(?BrowserPolicy $policy = null): void
    {
        if ($policy !== null && $policy->exists) {
            $this->authorize('update', $policy);
            $this->policy = $policy;
            $this->name = $policy->name;
            $this->project_id = $policy->project_id;
            $this->type = $policy->type->value;
            $this->browsers = $policy->browsers ?? ['all'];
            $this->action = $policy->action;
            $this->status = $policy->status;
            $this->description = $policy->description;
        } else {
            $this->authorize('create', BrowserPolicy::class);
        }
    }

    public function save()
    {
        $this->authorize($this->policy ? 'update' : 'create', $this->policy ?? BrowserPolicy::class);

        $validated = $this->validate([
            'name'        => ['required', 'string', 'max:255'],
            'project_id'  => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'type'        => ['required', Rule::in(BrowserPolicyType::values())],
            'browsers'    => ['required', 'array', 'min:1'],
            'browsers.*'  => [Rule::in(['all', ...Browser::values()])],
            'action'      => ['required', Rule::in(BrowserPolicy::ACTIONS)],
            'status'      => ['required', Rule::in(BrowserPolicy::STATUSES)],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], ['project_id' => 'project']);

        // "All browsers" swallows individual selections.
        $validated['browsers'] = in_array('all', $validated['browsers'], true)
            ? ['all']
            : array_values($validated['browsers']);

        // One rule per project+type — two policies fighting over the same
        // registry value would be a conflict, not a configuration.
        $conflict = BrowserPolicy::where('project_id', $validated['project_id'])
            ->where('type', $validated['type'])
            ->when($this->policy, fn ($q) => $q->whereKeyNot($this->policy->id))
            ->exists();

        if ($conflict) {
            $this->addError('type', 'This project already has a policy of this type — edit that one instead.');

            return null;
        }

        if ($this->policy) {
            $this->policy->update($validated);
            session()->flash('status', 'Browser policy saved. Agents pick it up on their next check-in.');
        } else {
            BrowserPolicy::create($validated + ['created_by' => auth()->id()]);
            session()->flash('status', 'Browser policy created. Agents apply it on their next check-in.');
        }

        return $this->redirectRoute('browser-policies.index');
    }

    public function render()
    {
        return view('livewire.browser-policies.browser-policy-form', [
            'projects'        => Project::orderBy('name')->get(['id', 'name']),
            'typesByCategory' => BrowserPolicyType::byCategory(),
            'selectedType'    => BrowserPolicyType::tryFrom($this->type),
            'allBrowsers'     => Browser::cases(),
        ])->layout('layouts.app');
    }
}
