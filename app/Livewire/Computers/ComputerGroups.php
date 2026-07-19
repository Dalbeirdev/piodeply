<?php

namespace App\Livewire\Computers;

use App\Enums\Permission;
use App\Models\Computer;
use App\Models\ComputerGroup;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Device groups: create, delete, and curate membership. Groups cut across
 * clients and projects, so this is a staff-only surface — client-portal
 * users never manage grouping.
 */
class ComputerGroups extends Component
{
    public string $newName = '';

    public ?string $newDescription = null;

    /** Group whose membership panel is open. */
    public ?int $managingId = null;

    /** Computer picked in the add-member select. */
    public ?int $addComputerId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::ComputersView->value), 403);
        abort_if(auth()->user()->tenantClientId() !== null, 403);
    }

    public function create(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'newName'        => ['required', 'string', 'max:255', Rule::unique('computer_groups', 'name')],
            'newDescription' => ['nullable', 'string', 'max:500'],
        ], [], ['newName' => 'group name']);

        ComputerGroup::create([
            'name'        => $validated['newName'],
            'description' => $this->newDescription,
            'created_by'  => auth()->id(),
        ]);

        $this->reset(['newName', 'newDescription']);
        session()->flash('status', 'Group created — add machines from its Manage panel.');
    }

    public function delete(int $groupId): void
    {
        $this->authorizeManage();

        ComputerGroup::findOrFail($groupId)->delete();

        if ($this->managingId === $groupId) {
            $this->managingId = null;
        }

        session()->flash('status', 'Group deleted. Machines themselves are unaffected.');
    }

    public function manage(?int $groupId): void
    {
        $this->managingId = $groupId === $this->managingId ? null : $groupId;
        $this->addComputerId = null;
    }

    public function addMember(): void
    {
        $this->authorizeManage();

        $this->validate(['addComputerId' => ['required', 'integer', Rule::exists('computers', 'id')]], [], ['addComputerId' => 'computer']);

        ComputerGroup::findOrFail($this->managingId)
            ->computers()
            ->syncWithoutDetaching([$this->addComputerId]);

        $this->addComputerId = null;
    }

    public function removeMember(int $groupId, int $computerId): void
    {
        $this->authorizeManage();

        ComputerGroup::findOrFail($groupId)->computers()->detach($computerId);
    }

    public function render()
    {
        return view('livewire.computers.computer-groups', [
            'groups' => ComputerGroup::withCount('computers')
                ->with(['computers' => fn ($q) => $q->orderBy('hostname')->with('project.client')])
                ->orderBy('name')
                ->get(),
            'available' => Computer::orderBy('hostname')->get(['id', 'hostname']),
            'canManage' => auth()->user()->can(Permission::ComputersManage->value),
        ])->layout('layouts.app');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(Permission::ComputersManage->value), 403);
    }
}
