<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Team\TeamIndex;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-project technician confinement: no assignments = roam the whole
 * tenant (the default nobody has to configure); one or more = confined to
 * exactly those projects, on lists and on direct URLs alike.
 */
class ProjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Project $projectA;

    private Project $projectB;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->client = Client::factory()->create();
        $this->projectA = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Alpha Site']);
        $this->projectB = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Beta Site']);
        $this->tech = tap(User::factory()->create(['client_id' => $this->client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Technician->value));
    }

    public function test_unassigned_technicians_roam_the_whole_tenant(): void
    {
        $this->actingAs($this->tech)->get(route('projects.index'))
            ->assertOk()->assertSee('Alpha Site')->assertSee('Beta Site');

        $this->assertTrue($this->tech->canAccessProject($this->projectB->id));
    }

    public function test_an_assigned_technician_is_confined_everywhere(): void
    {
        $this->tech->assignedProjects()->attach($this->projectA->id);

        $machineA = Computer::factory()->create(['project_id' => $this->projectA->id, 'hostname' => 'ALPHA-PC']);
        $machineB = Computer::factory()->create(['project_id' => $this->projectB->id, 'hostname' => 'BETA-PC']);

        // Lists show only the assigned project's world.
        $this->actingAs($this->tech)->get(route('projects.index'))
            ->assertOk()->assertSee('Alpha Site')->assertDontSee('Beta Site');
        $this->actingAs($this->tech)->get(route('computers.index'))
            ->assertOk()->assertSee('ALPHA-PC')->assertDontSee('BETA-PC');

        // Direct URLs are blocked, not merely hidden.
        $this->actingAs($this->tech)->get(route('computers.show', $machineB))->assertForbidden();
        $this->actingAs($this->tech)->get(route('computers.show', $machineA))->assertOk();
    }

    public function test_the_owner_assigns_and_unassigns_from_the_team_page(): void
    {
        $owner = tap(User::factory()->create(['client_id' => $this->client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        Livewire::actingAs($owner)
            ->test(TeamIndex::class)
            ->set("assignProject.{$this->tech->id}", $this->projectA->id)
            ->call('assignToProject', $this->tech->id);

        $this->assertTrue($this->tech->fresh()->assignedProjects->contains($this->projectA->id));

        // Removing the last assignment restores full-tenant access.
        Livewire::actingAs($owner)
            ->test(TeamIndex::class)
            ->call('unassignFromProject', $this->tech->id, $this->projectA->id);

        $this->assertNull($this->tech->fresh()->visibleProjectIds());
    }

    public function test_owners_can_never_be_confined(): void
    {
        $owner = tap(User::factory()->create(['client_id' => $this->client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));
        $peer = tap(User::factory()->create(['client_id' => $this->client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        Livewire::actingAs($owner)
            ->test(TeamIndex::class)
            ->set("assignProject.{$peer->id}", $this->projectA->id)
            ->call('assignToProject', $peer->id);

        $this->assertSame(0, $peer->fresh()->assignedProjects()->count());
    }

    public function test_a_foreign_tenants_project_cannot_be_assigned(): void
    {
        $owner = tap(User::factory()->create(['client_id' => $this->client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));
        $foreign = Project::factory()->create(); // another client's project

        try {
            Livewire::actingAs($owner)
                ->test(TeamIndex::class)
                ->set("assignProject.{$this->tech->id}", $foreign->id)
                ->call('assignToProject', $this->tech->id);
            $this->fail('a foreign project must not be assignable');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }

        $this->assertSame(0, $this->tech->fresh()->assignedProjects()->count());
    }
}
