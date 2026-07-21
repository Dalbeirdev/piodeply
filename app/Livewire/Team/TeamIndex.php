<?php

namespace App\Livewire\Team;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * A client's own staff page: the account owner invites the people who will
 * run their fleet — without ever seeing, or needing, the platform's admin
 * area.
 *
 * Hard tenancy rules, enforced on every action, not just the view:
 * - only the account OWNER may use this page, and they see exactly the
 *   users bound to their own client;
 * - created users are bound to that same client — the binding comes from
 *   the session, never from the form;
 * - the roles on offer stop at their own organisation's ceiling: platform
 *   roles (Admin, Super Admin) can never be granted from here.
 */
class TeamIndex extends Component
{
    public bool $showCreate = false;

    public string $newName = '';

    public string $newEmail = '';

    public string $newPassword = '';

    public string $newRole = 'Technician';

    /**
     * The ladder an owner hands out inside their own organisation. Every
     * one of these is client-bound, so authority never reaches past their
     * own environment:
     *  - Client Owner: co-administrator — billing and the team included.
     *  - Manager:      runs the whole fleet; no billing, no team changes.
     *  - Technician:   deploys software and manages machines.
     *  - Viewer:       read-only.
     */
    public const GRANTABLE = [
        RoleEnum::ClientOwner->value,
        RoleEnum::Manager->value,
        RoleEnum::Technician->value,
        RoleEnum::Viewer->value,
    ];

    /** What each grantable role means, in the customer's own terms. */
    public const ROLE_HELP = [
        'Client Owner' => 'Administrator — full access, including billing and managing this team.',
        'Manager'      => 'Runs the fleet: projects, machines, policies, deployments. No billing or team changes.',
        'Technician'   => 'Deploys software and manages machines. Cannot change policies, billing or the team.',
        'Viewer'       => 'Read-only.',
    ];

    public function mount(): void
    {
        $this->assertTenantManager();
    }

    private function assertTenantManager(): void
    {
        abort_if(auth()->user()->tenantClientId() === null, 403, 'The Team page is for client accounts.');
        // Owner-gated, not permission-gated: a Manager runs the fleet, an
        // owner runs the organisation. Managing people is the second job.
        abort_unless(auth()->user()->isClientOwner(), 403, 'Only account owners can manage the team.');
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

        // A fellow owner is not a peer's to delete — that stays with
        // PioDeploy support, so an organisation can never lock itself out.
        if ($target->isClientOwner()) {
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

    /** member id => project id chosen in that row's assign dropdown. */
    public array $assignProject = [];

    /**
     * Confine a technician to a project (first assignment starts the
     * confinement; none = they roam the whole tenant). Owners are never
     * confinable — restricting the person who does the restricting is a
     * lockout waiting to happen.
     */
    public function assignToProject(int $userId): void
    {
        $this->assertTenantManager();

        $target = User::where('client_id', auth()->user()->tenantClientId())->findOrFail($userId);

        if ($target->isClientOwner()) {
            session()->flash('error', 'Owners always have access to every project.');

            return;
        }

        $project = \App\Models\Project::where('client_id', auth()->user()->tenantClientId())
            ->findOrFail((int) ($this->assignProject[$userId] ?? 0));

        $target->assignedProjects()->syncWithoutDetaching([$project->id]);

        activity('team')->causedBy(auth()->user())
            ->withProperties(['email' => $target->email, 'project' => $project->name])
            ->log('team_member_assigned_project');

        session()->flash('status', "{$target->name} is now limited to their assigned projects.");
    }

    public function unassignFromProject(int $userId, int $projectId): void
    {
        $this->assertTenantManager();

        $target = User::where('client_id', auth()->user()->tenantClientId())->findOrFail($userId);
        $target->assignedProjects()->detach($projectId);

        session()->flash('status', $target->assignedProjects()->count() === 0
            ? "{$target->name} now has access to all projects again."
            : "Assignment removed.");
    }

    public function render()
    {
        $this->assertTenantManager();

        return view('livewire.team.team-index', [
            'members' => User::with('assignedProjects')
                ->where('client_id', auth()->user()->tenantClientId())
                ->orderBy('name')->get(),
            'grantable' => self::GRANTABLE,
            'roleHelp'  => self::ROLE_HELP,
            'projects'  => \App\Models\Project::where('client_id', auth()->user()->tenantClientId())
                ->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
