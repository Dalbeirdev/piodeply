<?php

namespace App\Livewire\Admin;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Livewire\Component;
use App\Livewire\Concerns\WithCompactPagination;

class ManageUsers extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public bool $showCreate = false;

    public string $newName = '';

    public string $newEmail = '';

    public string $newPassword = '';

    public string $newRole = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Admin-created accounts replace public self-registration (disabled —
     * this is an MSP back office). Created verified: the admin vouches.
     */
    public function createUser(): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate([
            'newName'     => ['required', 'string', 'max:255'],
            'newEmail'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'newPassword' => ['required', 'string', \Illuminate\Validation\Rules\Password::default()],
            'newRole'     => ['required', \Illuminate\Validation\Rule::in(RoleEnum::values())],
        ], [], [
            'newName' => 'name', 'newEmail' => 'email', 'newPassword' => 'password', 'newRole' => 'role',
        ]);

        // Only role managers may hand out roles; everyone else creates Viewers.
        if (! auth()->user()->can(\App\Enums\Permission::RolesManage->value)
            && $validated['newRole'] !== RoleEnum::Viewer->value) {
            $this->addError('newRole', 'You may only create Viewer accounts.');

            return;
        }

        // The Super Admin role is never assigned through this form.
        if ($validated['newRole'] === RoleEnum::SuperAdmin->value) {
            $this->addError('newRole', 'Super Admin cannot be assigned here.');

            return;
        }

        $user = User::create([
            'name'     => $validated['newName'],
            'email'    => $validated['newEmail'],
            'password' => \Illuminate\Support\Facades\Hash::make($validated['newPassword']),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($validated['newRole']);

        activity('rbac')
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties(['role' => $validated['newRole']])
            ->log('user_created');

        $this->reset(['showCreate', 'newName', 'newEmail', 'newPassword', 'newRole']);
        session()->flash('status', "User “{$user->name}” created.");
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

    public function setClient(int $userId, ?string $clientId): void
    {
        $target = User::findOrFail($userId);

        abort_if($target->is(auth()->user()), 403, 'You cannot change your own client binding.');
        $this->authorize('assignRole', $target);

        $clientId = $clientId === null || $clientId === '' ? null : (int) $clientId;
        if ($clientId !== null) {
            abort_unless(\App\Models\Client::whereKey($clientId)->exists(), 422);
        }

        $previous = $target->client_id;
        $target->forceFill(['client_id' => $clientId])->save();

        activity('rbac')
            ->causedBy(auth()->user())
            ->performedOn($target)
            ->withProperties(['from' => $previous, 'to' => $clientId])
            ->log('client_assigned');

        $this->dispatch('role-updated');
    }

    public function render()
    {
        $this->authorize('viewAny', User::class);

        return view('livewire.admin.manage-users', [
            'clients' => \App\Models\Client::orderBy('company_name')->get(['id', 'company_name']),
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
