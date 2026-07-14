<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role->value));
    }

    public function test_seeder_creates_all_roles_and_permissions_idempotently(): void
    {
        $this->assertSame(count(RoleEnum::values()), Role::count());
        $this->assertSame(count(PermissionEnum::values()), Permission::count());

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(RoleEnum::values()), Role::count());
        $this->assertSame(count(PermissionEnum::values()), Permission::count());
    }

    public function test_super_admin_passes_every_ability_including_undefined_ones(): void
    {
        $superAdmin = $this->userWithRole(RoleEnum::SuperAdmin);

        $this->assertTrue($superAdmin->can(PermissionEnum::SettingsManage->value));
        $this->assertTrue($superAdmin->can('some.future.permission'));
    }

    public function test_role_matrix_examples(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);
        $manager = $this->userWithRole(RoleEnum::Manager);
        $technician = $this->userWithRole(RoleEnum::Technician);
        $client = $this->userWithRole(RoleEnum::Client);
        $viewer = $this->userWithRole(RoleEnum::Viewer);

        $this->assertTrue($admin->can('settings.manage'));
        $this->assertTrue($manager->can('deployments.manage'));
        $this->assertFalse($manager->can('settings.manage'));
        $this->assertTrue($technician->can('computers.manage'));
        $this->assertFalse($technician->can('clients.create'));
        $this->assertTrue($client->can('reports.view'));
        $this->assertFalse($client->can('clients.view'));
        $this->assertTrue($viewer->can('packages.view'));
        $this->assertFalse($viewer->can('packages.manage'));
        $this->assertFalse($viewer->can('users.view'), 'viewers must not browse the user directory');
    }

    public function test_permission_middleware_gates_users_page(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');

        $technician = $this->userWithRole(RoleEnum::Technician); // no users.view
        $this->actingAs($technician)->get('/admin/users')->assertForbidden();

        $admin = $this->userWithRole(RoleEnum::Admin);
        $this->actingAs($admin)->get('/admin/users')->assertOk();

        $superAdmin = $this->userWithRole(RoleEnum::SuperAdmin);
        $this->actingAs($superAdmin)->get('/admin/users')->assertOk();
    }

    public function test_dynamic_menu_reflects_permissions(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);
        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Users');

        $technician = $this->userWithRole(RoleEnum::Technician);
        $this->actingAs($technician)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('admin/users');
    }

    public function test_admin_can_assign_roles_via_users_page(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setRole', $target->id, RoleEnum::Technician->value);

        $this->assertTrue($target->fresh()->hasRole(RoleEnum::Technician->value));
        $this->assertDatabaseHas('activity_log', [
            'log_name'    => 'rbac',
            'description' => 'role_assigned',
            'subject_id'  => $target->id,
        ]);
    }

    public function test_users_without_roles_manage_cannot_assign_roles(): void
    {
        $manager = $this->userWithRole(RoleEnum::Manager); // users.view but not roles.manage
        $target = User::factory()->create();

        Livewire::actingAs($manager)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setRole', $target->id, RoleEnum::Admin->value)
            ->assertForbidden();

        $this->assertFalse($target->fresh()->hasRole(RoleEnum::Admin->value));
    }

    public function test_admins_cannot_change_their_own_role(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setRole', $admin->id, RoleEnum::Viewer->value)
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->hasRole(RoleEnum::Admin->value));
    }

    public function test_even_super_admin_cannot_change_own_role(): void
    {
        $superAdmin = $this->userWithRole(RoleEnum::SuperAdmin);

        Livewire::actingAs($superAdmin)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setRole', $superAdmin->id, RoleEnum::Viewer->value)
            ->assertForbidden();

        $this->assertTrue($superAdmin->fresh()->hasRole(RoleEnum::SuperAdmin->value));
    }

    public function test_invalid_role_name_is_rejected(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin);
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setRole', $target->id, 'Root Overlord')
            ->assertStatus(422);
    }
}
