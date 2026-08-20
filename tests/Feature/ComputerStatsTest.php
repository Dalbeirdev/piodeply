<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputersIndex;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
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

    /**
     * Two different problems, counted apart: an agent that is merely behind
     * fixes itself, whereas one below the self-update floor is stuck until
     * somebody re-enrols it. Lumping them together hid the ones that
     * actually need a human.
     */
    public function test_agents_that_can_self_update_are_counted_apart_from_stranded_ones(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        // Behind, but able to update itself.
        Computer::factory()->create(['project_id' => $project->id, 'agent_version' => '1.4.20']);
        // Too old to self-update, and one that never reported a version.
        Computer::factory()->create(['project_id' => $project->id, 'agent_version' => '1.3.4']);
        Computer::factory()->create(['project_id' => $project->id, 'agent_version' => null]);
        // Current.
        Computer::factory()->create([
            'project_id'    => $project->id,
            'agent_version' => \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION,
        ]);

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(ComputersIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['update_available'] === 1 && $s['stranded'] === 2);
    }

    /* ─────────── fleet-wide software cards ─────────── */

    public function test_fleet_software_stats_count_machines_not_software_items(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        // One machine, two outdated items on it: still ONE machine counted.
        $outdatedMachine = Computer::factory()->create(['project_id' => $project->id]);
        ComputerSoftware::factory()->create([
            'computer_id' => $outdatedMachine->id, 'name' => 'Google.Chrome',
            'version' => '138.0', 'available_version' => '141.0', 'source' => 'winget',
        ]);
        ComputerSoftware::factory()->create([
            'computer_id' => $outdatedMachine->id, 'name' => 'Mozilla.Firefox',
            'version' => '139.0', 'available_version' => '141.0', 'source' => 'winget',
        ]);

        // Reported in, nothing outdated.
        $currentMachine = Computer::factory()->create(['project_id' => $project->id]);
        ComputerSoftware::factory()->create([
            'computer_id' => $currentMachine->id, 'name' => 'Notepad++.Notepad++',
            'version' => '8.9', 'available_version' => null, 'source' => 'winget',
        ]);

        // Never reported any software at all — must not count as "up to date".
        Computer::factory()->create(['project_id' => $project->id]);

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(ComputersIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['software_total'] === 3
                && $s['software_outdated'] === 1
                && $s['software_uptodate'] === 1);
    }

    public function test_fleet_pending_counts_machines_with_an_in_flight_job(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $package = Package::factory()->create();

        $busy = Computer::factory()->create(['project_id' => $project->id]);
        DeploymentJob::factory()->create([
            'computer_id' => $busy->id, 'package_id' => $package->id, 'status' => JobStatus::Running,
        ]);
        Computer::factory()->create(['project_id' => $project->id]); // idle

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(ComputersIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['software_pending'] === 1);
    }

    public function test_the_update_required_card_filters_the_list_to_matching_machines(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        $outdated = Computer::factory()->create(['project_id' => $project->id, 'hostname' => 'OUTDATED-PC']);
        ComputerSoftware::factory()->create([
            'computer_id' => $outdated->id, 'name' => 'Google.Chrome',
            'version' => '138.0', 'available_version' => '141.0', 'source' => 'winget',
        ]);
        $current = Computer::factory()->create(['project_id' => $project->id, 'hostname' => 'CURRENT-PC']);
        ComputerSoftware::factory()->create([
            'computer_id' => $current->id, 'name' => 'Notepad++.Notepad++',
            'version' => '8.9', 'available_version' => null, 'source' => 'winget',
        ]);

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(ComputersIndex::class)
            ->set('softwareStatus', 'outdated')
            ->assertSee('OUTDATED-PC')
            ->assertDontSee('CURRENT-PC');
    }
}
