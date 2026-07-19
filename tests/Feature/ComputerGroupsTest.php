<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputerGroups;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerGroup;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Device groups: staff-only CRUD and membership curation, cutting across
 * clients and projects.
 */
class ComputerGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    public function test_admin_can_create_and_delete_a_group(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ComputerGroups::class)
            ->set('newName', 'Finance workstations')
            ->set('newDescription', 'Machines handling payments')
            ->call('create')
            ->assertHasNoErrors();

        $group = ComputerGroup::firstOrFail();
        $this->assertSame('Finance workstations', $group->name);

        Livewire::actingAs($this->admin())
            ->test(ComputerGroups::class)
            ->call('delete', $group->id);

        $this->assertDatabaseCount('computer_groups', 0);
    }

    public function test_membership_can_span_clients_and_projects(): void
    {
        $a = Computer::factory()->create(['hostname' => 'ALPHA-01']);
        $b = Computer::factory()->create(['hostname' => 'BETA-01']); // different auto-created project/client
        $group = ComputerGroup::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ComputerGroups::class)
            ->call('manage', $group->id)
            ->set('addComputerId', $a->id)
            ->call('addMember')
            ->set('addComputerId', $b->id)
            ->call('addMember');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $group->computers()->pluck('computers.id')->all());
        $this->assertNotSame($a->fresh()->project_id, $b->fresh()->project_id);

        // Adding twice is idempotent; removing detaches without deleting.
        Livewire::actingAs($this->admin())
            ->test(ComputerGroups::class)
            ->call('manage', $group->id)
            ->set('addComputerId', $a->id)
            ->call('addMember')
            ->call('removeMember', $group->id, $b->id);

        $this->assertSame([$a->id], $group->computers()->pluck('computers.id')->all());
        $this->assertNotNull($b->fresh());
    }

    public function test_deleting_a_computer_cleans_its_memberships(): void
    {
        $computer = Computer::factory()->create();
        $group = ComputerGroup::factory()->create();
        $group->computers()->attach($computer);

        $computer->forceDelete();

        $this->assertSame(0, $group->computers()->count());
    }

    public function test_client_portal_users_cannot_open_the_groups_page(): void
    {
        $client = Client::factory()->create();
        $tenant = tap(User::factory()->create(['client_id' => $client->id]), fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        Livewire::actingAs($tenant)
            ->test(ComputerGroups::class)
            ->assertForbidden();
    }

    public function test_route_is_not_swallowed_by_the_computer_binding(): void
    {
        $this->actingAs($this->admin())
            ->get('/computers/groups')
            ->assertOk()
            ->assertSee('Device Groups');
    }
}
