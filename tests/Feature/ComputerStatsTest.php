<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputersIndex;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The figures above the Computers table are scoped exactly like the table.
 * A headline count that ignored tenancy would tell one client how large
 * another client's fleet is.
 */
class ComputerStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function fleetFor(Client $client, int $online, int $offline): Project
    {
        $project = Project::factory()->create(['client_id' => $client->id]);

        Computer::factory()->count($online)->create([
            'project_id'   => $project->id,
            'last_seen_at' => now(),
        ]);
        Computer::factory()->count($offline)->create([
            'project_id'   => $project->id,
            'last_seen_at' => now()->subDay(),
        ]);

        return $project;
    }

    public function test_a_client_counts_only_their_own_machines(): void
    {
        $mine = Client::factory()->create();
        $theirs = Client::factory()->create();

        $this->fleetFor($mine, online: 2, offline: 1);
        $this->fleetFor($theirs, online: 5, offline: 4);

        $owner = User::factory()->create();
        $owner->forceFill(['client_id' => $mine->id])->save();
        $owner->assignRole(RoleEnum::ClientOwner->value);

        Livewire::actingAs($owner)
            ->test(ComputersIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['total'] === 3 && $s['online'] === 2 && $s['offline'] === 1);
    }

    public function test_staff_see_the_whole_fleet(): void
    {
        $this->fleetFor(Client::factory()->create(), online: 2, offline: 1);
        $this->fleetFor(Client::factory()->create(), online: 1, offline: 1);

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(ComputersIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['total'] === 5 && $s['online'] === 3 && $s['offline'] === 2);
    }

    public function test_machines_below_the_current_agent_are_counted_as_outdated(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        Computer::factory()->create(['project_id' => $project->id, 'agent_version' => '1.0.0']);
        Computer::factory()->create(['project_id' => $project->id, 'agent_version' => null]);
        Computer::factory()->create([
            'project_id'    => $project->id,
            'agent_version' => \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION,
        ]);

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(ComputersIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['outdated'] === 2);
    }
}
