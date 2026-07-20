<?php

namespace App\Livewire\Team;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * A client's own staff page: the owner (client-bound Manager) invites the
 * technicians who will run their fleet — without ever seeing, or needing,
 * the platform's admin area.
 *
 * Hard tenancy rules, enforced on every action, not just the view:
 * - only client-bound users may use this page, and they see exactly the
 *   users bound to their own client;
 * - created users are bound to that same client — the binding comes from
 *   the session, never from the form;
 * - only Technician and Viewer can be handed out here. Manager (a peer
 *   owner) is deliberately the platform admin's call.
 */
class TeamIndex extends Component
{
    public bool $showCreate = false;

    public string $newName = '';

    public string $newEmail = '';

    public string $newPassword = '';

    public string $newRole = 'Technician';

    /** The roles a client owner may grant. */
    public const GRANTABLE = [RoleEnum::Technician->value, RoleEnum::Viewer->value];

    public function mount(): void
    {
        $this->assertTenantManager();
    }

    private function assertTenantManager(): void
    {
        abort_if(auth()->user()->tenantClientId() === null, 403, 'The Team page is for client accounts.');
        abort_unless(auth()->user()->can(\App\Enums\Permission::UsersView->value), 403);
    }

    public function create(): void
    {
        $this->assertTenantManager();

        $validated = $this->validate([
            'newName'     => ['required', 'string', 'max:255'],
            'newEmail'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'newPassword' => ['required', 'string', \Illuminate\Validation\Rules\Password::default()],
            'newRole'     => ['required', Rule::in(self::GRANTABLE)],
        ], [], [
            'newName' => 'name', 'newEmail' => 'email', 'newPassword' => 'password', 'newRole' => 'role',
        ]);

        $user = new User([
            'name'     => $validated['newName'],
            'email'    => $validated['newEmail'],
            'password' => Hash::make($validated['newPassword']),
        ]);
        $user->forceFill([
            'client_id'         => auth()->user()->tenantClientId(),
            'email_verified_at' => now(),
        ])->save();

        $user->assignRole($validated['newRole']);

        activity('team')
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties(['role' => $validated['newRole']])
            ->log('team_member_created');

        $this->reset(['showCreate', 'newName', 'newEmail', 'newPassword']);
        $this->newRole = 'Technician';
        session()->flash('status', "{$user->name} can now sign in with the password you set.");
    }

    public function remove(int $userId): void
    {
        $this->assertTenantManager();

        $target = User::where('client_id', auth()->user()->tenantClientId())->findOrFail($userId);

        if ($target->id === auth()->id()) {
            session()->flash('error', 'You cannot remove yourself.');

            return;
        }

        // A fellow owner (Client Owner, or a staff-granted Manager) is the
        // platform admin's to manage, not a peer's.
        if ($target->hasRole(RoleEnum::Manager->value) || $target->hasRole(RoleEnum::ClientOwner->value)) {
            session()->flash('error', 'Owner accounts are managed by PioDeploy support.');

            return;
        }

        activity('team')
            ->causedBy(auth()->user())
            ->withProperties(['email' => $target->email])
            ->log('team_member_removed');

        $target->delete();
        session()->flash('status', "{$target->name} removed.");
    }

    public function render()
    {
        $this->assertTenantManager();

        return view('livewire.team.team-index', [
            'members' => User::where('client_id', auth()->user()->tenantClientId())
                ->orderBy('name')->get(),
            'grantable' => self::GRANTABLE,
        ])->layout('layouts.app');
    }
}
