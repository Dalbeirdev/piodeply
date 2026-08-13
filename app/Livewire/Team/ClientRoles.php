<?php

namespace App\Livewire\Team;

use App\Models\ClientRole;
use App\Models\Computer;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The owner's custom-role builder: define WHAT a role may do
 * (install / update / uninstall) and WHERE (every machine, or an explicit
 * list of their own machines). Assigned on the Team page; enforced at the
 * deployment funnel and in machine visibility.
 *
 * Same hard tenancy as the Team page: owner-only, and every read or write
 * is confined to the owner's own client.
 */
class ClientRoles extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $can_install = false;

    public bool $can_update = true;

    public bool $can_uninstall = false;

    /** 'all' | 'sites' | 'computers' */
    public string $scope = 'all';

    /** @var list<int> */
    public array $computerIds = [];

    /** @var list<int> */
    public array $projectIds = [];

    public bool $showForm = false;

    public function mount(): void
    {
        $this->assertOwner();
    }

    private function assertOwner(): void
    {
        abort_if(auth()->user()->tenantClientId() === null, 403, 'Custom roles are for client accounts.');
        abort_unless(auth()->user()->isClientOwner(), 403, 'Only account owners can manage roles.');
    }

    #[\Livewire\Attributes\On('client-roles-new')]
    public function startCreate(): void
    {
        $this->assertOwner();
        $this->reset(['editingId', 'name', 'description', 'can_install', 'can_update', 'can_uninstall', 'scope', 'computerIds', 'projectIds']);
        $this->showForm = true;
    }

    public function edit(int $roleId): void
    {
        $this->assertOwner();
        $role = $this->ownRole($roleId);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->can_install = $role->can_install;
        $this->can_update = $role->can_update;
        $this->can_uninstall = $role->can_uninstall;
        $this->scope = $role->scope;
        $this->computerIds = $role->computers()->pluck('computers.id')->all();
        $this->projectIds = $role->projects()->pluck('projects.id')->all();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->assertOwner();

        $validated = $this->validate([
            'name'          => ['required', 'string', 'max:60'],
            'description'   => ['nullable', 'string', 'max:200'],
            'can_install'   => ['boolean'],
            'can_update'    => ['boolean'],
            'can_uninstall' => ['boolean'],
            'scope'         => ['required', Rule::in(ClientRole::SCOPES)],
            'computerIds'   => ['array'],
            'computerIds.*' => ['integer'],
            'projectIds'    => ['array'],
            'projectIds.*'  => ['integer'],
        ]);

        if (! $validated['can_install'] && ! $validated['can_update'] && ! $validated['can_uninstall']) {
            $this->addError('can_update', 'Pick at least one action the role allows.');

            return;
        }

        if ($validated['scope'] === 'computers' && $validated['computerIds'] === []) {
            $this->addError('computerIds', 'Select at least one machine, or widen the scope.');

            return;
        }

        if ($validated['scope'] === 'sites' && $validated['projectIds'] === []) {
            $this->addError('projectIds', 'Select at least one '.project_term_lower().', or widen the scope.');

            return;
        }

        // Machines and sites are validated against the owner's OWN tenant —
        // ids from another client are silently impossible.
        $machineIds = $validated['scope'] !== 'computers' ? [] : Computer::visibleTo(auth()->user())
            ->whereIn('computers.id', $validated['computerIds'])
            ->pluck('computers.id')->all();
        $siteIds = $validated['scope'] !== 'sites' ? [] : Project::where('client_id', auth()->user()->tenantClientId())
            ->whereIn('id', $validated['projectIds'])
            ->pluck('id')->all();

        $attributes = [
            'name'          => $validated['name'],
            'description'   => $validated['description'] ?: null,
            'can_install'   => $validated['can_install'],
            'can_update'    => $validated['can_update'],
            'can_uninstall' => $validated['can_uninstall'],
            'scope'         => $validated['scope'],
        ];

        $role = $this->editingId !== null
            ? tap($this->ownRole($this->editingId))->update($attributes)
            : ClientRole::create([...$attributes, 'client_id' => auth()->user()->tenantClientId()]);

        $role->computers()->sync($machineIds);
        $role->projects()->sync($siteIds);

        activity('team')
            ->causedBy(auth()->user())
            ->performedOn($role)
            ->withProperties($attributes + ['machines' => count($machineIds), 'sites' => count($siteIds)])
            ->log($this->editingId !== null ? 'client_role_updated' : 'client_role_created');

        $this->reset(['showForm', 'editingId']);
        session()->flash('status', "Role \"{$role->name}\" saved.");
    }

    public function delete(int $roleId): void
    {
        $this->assertOwner();
        $role = $this->ownRole($roleId);

        if ($role->users()->exists()) {
            session()->flash('error', "\"{$role->name}\" is assigned to team members — move them to another role first.");

            return;
        }

        $role->delete();

        activity('team')->causedBy(auth()->user())->log('client_role_deleted');
        session()->flash('status', 'Role deleted.');
    }

    private function ownRole(int $roleId): ClientRole
    {
        return ClientRole::where('client_id', auth()->user()->tenantClientId())
            ->findOrFail($roleId);
    }

    public function render()
    {
        return view('livewire.team.client-roles', [
            'roles'    => ClientRole::withCount('users')
                ->where('client_id', auth()->user()->tenantClientId())
                ->orderBy('name')->get(),
            'machines' => Computer::visibleTo(auth()->user())
                ->orderBy('hostname')->get(['computers.id', 'hostname']),
            'sites'    => Project::where('client_id', auth()->user()->tenantClientId())
                ->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
