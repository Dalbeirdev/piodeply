<?php

namespace App\Livewire\BrowserPolicies;

use App\Enums\Browser;
use App\Enums\BrowserPolicyType;
use App\Models\BrowserPolicy;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Component;

class BrowserPolicyForm extends Component
{
    public ?BrowserPolicy $policy = null;

    public string $name = '';

    /** Assignment: what kind of thing the policy targets, and which one. */
    public string $scope_type = 'project';

    public ?int $scope_id = null;

    public string $type = 'disable_incognito';

    /** @var list<string> 'all' or Browser values */
    public array $browsers = ['all'];

    public string $action = 'disable';

    public string $status = 'active';

    public ?string $description = null;

    /** Value-typed payloads: a URL, or extension ids one per line. */
    public string $value_url = '';

    public string $value_ids = '';

    public function mount(?BrowserPolicy $policy = null): void
    {
        if ($policy !== null && $policy->exists) {
            $this->authorize('update', $policy);
            $this->policy = $policy;
            $this->name = $policy->name;
            $this->scope_type = $policy->scope_type;
            $this->scope_id = $policy->scope_id === 0 ? null : $policy->scope_id;
            $this->type = $policy->type->value;
            $this->browsers = $policy->browsers ?? ['all'];
            $this->action = $policy->action;
            $this->status = $policy->status;
            $this->description = $policy->description;
            $this->value_url = $policy->settings['url'] ?? '';
            $this->value_ids = implode("\n", $policy->settings['ids'] ?? []);
        } else {
            $this->authorize('create', BrowserPolicy::class);
        }
    }

    public function save()
    {
        $this->authorize($this->policy ? 'update' : 'create', $this->policy ?? BrowserPolicy::class);

        $valueKind = BrowserPolicyType::tryFrom($this->type)?->valueKind();

        $scopeIdRule = match ($this->scope_type) {
            'all'      => ['nullable'],
            'client'   => ['required', 'integer', Rule::exists('clients', 'id')],
            'project'  => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'group'    => ['required', 'integer', Rule::exists('computer_groups', 'id')],
            'computer' => ['required', 'integer', Rule::exists('computers', 'id')],
            default    => ['required'],
        };

        $validated = $this->validate([
            'name'        => ['required', 'string', 'max:255'],
            'scope_type'  => ['required', Rule::in(array_keys(BrowserPolicy::SPECIFICITY))],
            'scope_id'    => $scopeIdRule,
            'type'        => ['required', Rule::in(BrowserPolicyType::values())],
            'browsers'    => ['required', 'array', 'min:1'],
            'browsers.*'  => [Rule::in(['all', ...Browser::values()])],
            'action'      => ['required', Rule::in(BrowserPolicy::ACTIONS)],
            'status'      => ['required', Rule::in(BrowserPolicy::STATUSES)],
            'description' => ['nullable', 'string', 'max:1000'],
            'value_url'   => [Rule::requiredIf($valueKind === 'url'), 'nullable', 'url:http,https', 'max:500'],
            'value_ids'   => [Rule::requiredIf($valueKind === 'ids'), 'nullable', 'string', 'max:4000'],
        ], [
            'value_url.required' => 'This policy needs the URL to enforce.',
            'value_ids.required' => 'List at least one extension ID (one per line).',
        ], ['scope_id' => 'target', 'value_url' => 'URL', 'value_ids' => 'extension IDs']);

        // Normalise the scope: 'all' targets the instance (id 0); a project
        // scope also keeps project_id filled for the relation and reports.
        $validated['scope_id'] = $this->scope_type === 'all' ? 0 : (int) $validated['scope_id'];
        $validated['project_id'] = $this->scope_type === 'project' ? $validated['scope_id'] : null;

        // "All browsers" swallows individual selections.
        $validated['browsers'] = in_array('all', $validated['browsers'], true)
            ? ['all']
            : array_values($validated['browsers']);

        // The value payload, shaped for the type. Chrome Web Store ids are
        // 32 letters a–p; rejecting anything else stops a typo becoming a
        // registry entry no browser will ever match.
        $settings = null;
        if ($valueKind === 'url') {
            $settings = ['url' => trim($this->value_url)];
        } elseif ($valueKind === 'ids') {
            $ids = collect(preg_split('/\R+/', trim($this->value_ids)))
                ->map(fn ($id) => strtolower(trim($id)))
                ->filter()
                ->unique()
                ->values();

            $invalid = $ids->first(fn ($id) => preg_match('/^[a-p]{32}$/', $id) !== 1);
            if ($invalid !== null) {
                $this->addError('value_ids', "\"{$invalid}\" is not a valid extension ID (32 letters a–p, from the Web Store URL).");

                return null;
            }

            $settings = ['ids' => $ids->all()];
        }
        $validated['settings'] = $settings;
        unset($validated['value_url'], $validated['value_ids']);

        // One rule per scope+type — two policies on the same target fighting
        // over the same registry value would be a conflict, not a
        // configuration. (Overlap across DIFFERENT scopes is fine: the
        // specificity order resolves it per machine.)
        $conflict = BrowserPolicy::where('scope_type', $this->scope_type)
            ->where('scope_id', $validated['scope_id'])
            ->where('type', $validated['type'])
            ->when($this->policy, fn ($q) => $q->whereKeyNot($this->policy->id))
            ->exists();

        if ($conflict) {
            $this->addError('type', 'This target already has a policy of this type — edit that one instead.');

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

    /** Changing the scope kind invalidates the picked target. */
    public function updatedScopeType(): void
    {
        $this->scope_id = null;
    }

    public function render()
    {
        return view('livewire.browser-policies.browser-policy-form', [
            'scopeOptions' => match ($this->scope_type) {
                'client'   => Client::orderBy('company_name')->get(['id', 'company_name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->company_name]),
                'project'  => Project::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->name]),
                'group'    => ComputerGroup::orderBy('name')->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'label' => $g->name]),
                'computer' => Computer::orderBy('hostname')->get(['id', 'hostname'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->hostname]),
                default    => collect(),
            },
            'typesByCategory' => BrowserPolicyType::byCategory(),
            'selectedType'    => BrowserPolicyType::tryFrom($this->type),
            'allBrowsers'     => Browser::cases(),
        ])->layout('layouts.app');
    }
}
