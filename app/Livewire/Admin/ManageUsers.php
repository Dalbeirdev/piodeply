<?php

namespace App\Livewire\Admin;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class ManageUsers extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function setRole(int $userId, string $role): void
    {
        $target = User::findOrFail($userId);

        // Hard rule, deliberately outside the policy: Gate::before lets a
        // Super Admin bypass policies, but nobody may change their own role.
        abort_if($target->is(auth()->user()), 403, 'You cannot change your own role.');

        $this->authorize('assignRole', $target);

        abort_unless(in_array($role, RoleEnum::values(), true), 422);

        $previous = $target->getRoleNames()->all();
        $target->syncRoles([$role]);

        activity('rbac')
            ->causedBy(auth()->user())
            ->performedOn($target)
            ->withProperties(['from' => $previous, 'to' => [$role]])
            ->log('role_assigned');

        $this->dispatch('role-updated');
    }

    public function render()
    {
        $this->authorize('viewAny', User::class);

        return view('livewire.admin.manage-users', [
            'users' => User::with('roles')
                ->when($this->search !== '', function ($query) {
                    $query->where(fn ($q) => $q
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%"));
                })
                ->orderBy('name')
                ->paginate(15),
            'roles' => RoleEnum::values(),
        ])->layout('layouts.app');
    }
}
