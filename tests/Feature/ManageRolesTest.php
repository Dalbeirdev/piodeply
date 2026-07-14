<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\ManageRoles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManageRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role->value));
    }

    public function test_admin_can_open_the_matrix(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);

        $this->actingAs($admin)
            ->get('/admin/roles')
            ->assertOk()
            ->assertSee('Roles & Permissions')
            ->assertSee('policies.manage');

        // Super Admin is never an editable column (the info banner may
        // mention it, so check the view data rather than the HTML).
        Livewire::actingAs($admin)
            ->test(ManageRoles::class)
            ->assertViewHas('roles', fn ($roles) => ! $roles->contains(RoleEnum::SuperAdmin->value)
                && $roles->contains(RoleEnum::Viewer->value));
    }

    public function test_manager_and_below_cannot_open_the_matrix(): void
    {
        foreach ([RoleEnum::Manager, RoleEnum::Technician, RoleEnum::Viewer] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/admin/roles')
                ->assertForbidden();
        }
    }

    public function test_toggle_grants_and_revokes_a_permission_immediately(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);
        $technician = $this->userWithRole(RoleEnum::Technician);

        $this->assertFalse($technician->can(PermissionEnum::PoliciesView->value));

        Livewire::actingAs($admin)
            ->test(ManageRoles::class)
            ->call('toggle', RoleEnum::Technician->value, PermissionEnum::PoliciesView->value);

        $this->assertTrue($technician->fresh()->can(PermissionEnum::PoliciesView->value));
        $this->assertDatabaseHas('activity_log', ['description' => 'permission_granted']);

        Livewire::actingAs($admin)
            ->test(ManageRoles::class)
            ->call('toggle', RoleEnum::Technician->value, PermissionEnum::PoliciesView->value);

        $this->assertFalse($technician->fresh()->can(PermissionEnum::PoliciesView->value));
        $this->assertDatabaseHas('activity_log', ['description' => 'permission_revoked']);
    }

    public function test_super_admin_role_cannot_be_edited(): void
    {
        Livewire::actingAs($this->userWithRole(RoleEnum::Admin))
            ->test(ManageRoles::class)
            ->call('toggle', RoleEnum::SuperAdmin->value, PermissionEnum::UsersView->value)
            ->assertForbidden();

        $this->assertCount(0, Role::findByName(RoleEnum::SuperAdmin->value, 'web')->permissions);
    }

    public function test_admin_cannot_revoke_roles_manage_from_their_own_role(): void
    {
        Livewire::actingAs($this->userWithRole(RoleEnum::Admin))
            ->test(ManageRoles::class)
            ->call('toggle', RoleEnum::Admin->value, PermissionEnum::RolesManage->value)
            ->assertForbidden();

        $this->assertTrue(
            Role::findByName(RoleEnum::Admin->value, 'web')
                ->hasPermissionTo(PermissionEnum::RolesManage->value)
        );
    }

    public function test_reset_restores_the_default_matrix(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);

        Livewire::actingAs($admin)
            ->test(ManageRoles::class)
            ->call('toggle', RoleEnum::Viewer->value, PermissionEnum::UsersView->value);
        $this->assertTrue(Role::findByName(RoleEnum::Viewer->value, 'web')->hasPermissionTo(PermissionEnum::UsersView->value));

        Livewire::actingAs($admin)
            ->test(ManageRoles::class)
            ->call('resetDefaults');

        // Viewer's least-privilege default (no user directory) is back.
        $this->assertFalse(Role::findByName(RoleEnum::Viewer->value, 'web')->hasPermissionTo(PermissionEnum::UsersView->value));
        $this->assertDatabaseHas('activity_log', ['description' => 'permissions_reset_to_defaults']);
    }

    public function test_rejects_unknown_role_or_permission(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);

        Livewire::actingAs($admin)
            ->test(ManageRoles::class)
            ->call('toggle', 'Warlord', PermissionEnum::UsersView->value)
            ->assertStatus(422);

        Livewire::actingAs($admin)
            ->test(ManageRoles::class)
            ->call('toggle', RoleEnum::Viewer->value, 'universe.destroy')
            ->assertStatus(422);
    }

    public function test_roles_nav_item_only_shows_for_role_managers(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);
        $viewer = $this->userWithRole(RoleEnum::Viewer);
        $service = app(\App\Services\NavigationService::class);

        $this->assertContains('Roles', array_column($service->items($admin), 'label'));
        $this->assertNotContains('Roles', array_column($service->items($viewer), 'label'));
    }
}
