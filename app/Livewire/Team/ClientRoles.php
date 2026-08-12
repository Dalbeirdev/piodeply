<?php

namespace App\Livewire\Team;

use App\Models\ClientRole;
use App\Models\Computer;
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

    public bool $all_computers = true;

    /** @var list<int> */
    public array $computerIds = [];

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
        $this->reset(['editingId', 'name', 'description', 'can_install', 'can_update', 'can_uninstall', 'all_computers', 'computerIds']);
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
        $this->all_computers = $role->all_computers;
        $this->computerIds = $role->computers()->pluck('computers.id')->all();
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
            'all_computers' => ['boolean'],
            'computerIds'   => ['array'],
            'computerIds.*' => ['integer'],
        ]);

        if (! $validated['can_install'] && ! $validated['can_update'] && ! $validated['can_uninstall']) {
            $this->addError('can_update', 'Pick at least one action the role allows.');

            return;
        }

        if (! $validated['all_computers'] && $validated['computerIds'] === []) {
            $this->addError('computerIds', 'Select at least one machine, or allow all machines.');

            return;
        }

        // Machines are validated against the owner's OWN fleet — ids from
        // another tenant are silently impossible.
        $machineIds = $validated['all_computers'] ? [] : Computer::visibleTo(auth()->user())
            ->whereIn('computers.id', $validated['computerIds'])
            ->pluck('computers.id')->all();

        $attributes = [
            'name'          => $validated['name'],
            'description'   => $validated['description'] ?: null,
            'can_install'   => $validated['can_install'],
            'can_update'    => $validated['can_update'],
            'can_uninstall' => $validated['can_uninstall'],
            'all_computers' => $validated['all_computers'],
        ];

        $role = $this->editingId !== null
            ? tap($this->ownRole($this->editingId))->update($attributes)
            : ClientRole::create([...$attributes, 'client_id' => auth()->user()->tenantClientId()]);

        $role->computers()->sync($machineIds);

        activity('team')
            ->causedBy(auth()->user())
            ->performedOn($role)
            ->withProperties($attributes + ['machines' => count($machineIds) ?: 'all'])
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
        ])->layout('layouts.app');
    }
}
