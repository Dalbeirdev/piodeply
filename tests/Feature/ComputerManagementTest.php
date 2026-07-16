<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputerEdit;
use App\Livewire\Computers\ComputersIndex;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Models\User;
use App\Services\ComputerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ComputerManagementTest extends TestCase
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

    private function viewer(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));
    }

    public function test_pages_are_permission_gated(): void
    {
        $computer = Computer::factory()->create();

        $this->get('/computers')->assertRedirect('/login');

        $this->actingAs($this->viewer())->get('/computers')->assertOk();
        $this->actingAs($this->viewer())->get("/computers/{$computer->id}")->assertOk();
        $this->actingAs($this->viewer())->get("/computers/{$computer->id}/edit")->assertForbidden();

        $technician = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Technician->value));
        $this->actingAs($technician)->get("/computers/{$computer->id}/edit")->assertOk();
    }

    public function test_registration_is_idempotent_per_agent_uuid(): void
    {
        $project = Project::factory()->create();
        $service = app(ComputerService::class);
        $uuid = (string) Str::uuid();

        $first = $service->register($project, $uuid, ['hostname' => 'PC-001', 'ram_bytes' => 8 * 1024 ** 3], '1.0.0');
        $second = $service->register($project, $uuid, ['hostname' => 'PC-001-RENAMED'], '1.0.1');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Computer::count());
        $this->assertSame('PC-001-RENAMED', $second->hostname);
        $this->assertSame('1.0.1', $second->agent_version);
        // fields not present in the new payload survive
        $this->assertSame(8 * 1024 ** 3, $second->ram_bytes);
    }

    public function test_reregistration_revives_a_soft_deleted_computer(): void
    {
        $project = Project::factory()->create();
        $service = app(ComputerService::class);
        $computer = Computer::factory()->for($project)->create();
        $service->delete($computer);
        $this->assertSoftDeleted('computers', ['id' => $computer->id]);

        $revived = $service->register($project, $computer->agent_uuid, ['hostname' => $computer->hostname]);

        $this->assertTrue($revived->is($computer));
        $this->assertNull($revived->deleted_at);
        $this->assertSame(1, Computer::count());
    }

    public function test_registration_ignores_non_inventory_fields(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        $computer = app(ComputerService::class)->register($project, (string) Str::uuid(), [
            'hostname'   => 'PC-SAFE',
            'project_id' => $other->id,   // must not be mass-assignable via inventory
            'agent_uuid' => 'spoofed',
            'id'         => 999,
        ]);

        $this->assertSame($project->id, $computer->project_id);
        $this->assertNotSame('spoofed', $computer->agent_uuid);
    }

    public function test_heartbeat_updates_last_seen_and_version_without_activity_log(): void
    {
        $computer = Computer::factory()->create(['last_seen_at' => now()->subHours(5), 'agent_version' => '1.0.0']);
        $logCountBefore = \DB::table('activity_log')->count();

        app(ComputerService::class)->heartbeat($computer, '1.0.2');

        $computer->refresh();
        $this->assertTrue($computer->isOnline());
        $this->assertSame('1.0.2', $computer->agent_version);
        $this->assertSame($logCountBefore, \DB::table('activity_log')->count(), 'heartbeats must not write activity log');
    }

    public function test_online_status_is_derived_from_last_seen(): void
    {
        $online = Computer::factory()->online()->create();
        $offline = Computer::factory()->offline()->create();
        $never = Computer::factory()->neverSeen()->create();

        $this->assertTrue($online->isOnline());
        $this->assertFalse($offline->isOnline());
        $this->assertFalse($never->isOnline());

        $this->assertSame([$online->id], Computer::online()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$offline->id, $never->id], Computer::offline()->pluck('id')->all());
    }

    public function test_index_filters_by_search_client_and_connectivity(): void
    {
        $acme = Client::factory()->create(['company_name' => 'Acme Corp']);
        $acmeProject = Project::factory()->for($acme)->create();
        Computer::factory()->for($acmeProject)->online()->create(['hostname' => 'ACME-PC-01']);
        Computer::factory()->offline()->create(['hostname' => 'OTHER-PC-99']);

        Livewire::actingAs($this->admin())
            ->test(ComputersIndex::class)
            ->set('search', 'ACME')
            ->assertSee('ACME-PC-01')
            ->assertDontSee('OTHER-PC-99')
            ->set('search', '')
            ->set('clientId', $acme->id)
            ->assertSee('ACME-PC-01')
            ->assertDontSee('OTHER-PC-99')
            ->set('clientId', null)
            ->set('connectivity', 'offline')
            ->assertSee('OTHER-PC-99')
            ->assertDontSee('ACME-PC-01');
    }

    public function test_show_page_displays_inventory_and_security_posture(): void
    {
        $computer = Computer::factory()->create([
            'hostname'    => 'SHOW-ME',
            'cpu'         => 'Intel Core i7-1355U',
            'ram_bytes'   => 16 * 1024 ** 3,
            'secure_boot' => true,
            'tpm_enabled' => true,
            'tpm_version' => '2.0',
        ]);

        $this->actingAs($this->admin())
            ->get("/computers/{$computer->id}")
            ->assertOk()
            ->assertSee('SHOW-ME')
            ->assertSee('Intel Core i7-1355U')
            ->assertSee('16 GB')
            ->assertSee('Secure Boot')
            ->assertSee('Enabled (v2.0)');
    }

    public function test_reassign_moves_computer_and_logs_activity(): void
    {
        $computer = Computer::factory()->create();
        $target = Project::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ComputerEdit::class, ['computer' => $computer])
            ->set('project_id', $target->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($target->id, $computer->fresh()->project_id);
        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'computers',
            'subject_type' => Computer::class,
            'subject_id'   => $computer->id,
            'description'  => 'updated',
        ]);
    }

    public function test_viewer_cannot_delete_computers(): void
    {
        $computer = Computer::factory()->create();

        Livewire::actingAs($this->viewer())
            ->test(ComputersIndex::class)
            ->call('delete', $computer->id)
            ->assertForbidden();

        $this->assertNull($computer->fresh()->deleted_at);
    }

    public function test_soft_delete_and_restore(): void
    {
        $computer = Computer::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ComputersIndex::class)
            ->call('delete', $computer->id);
        $this->assertSoftDeleted('computers', ['id' => $computer->id]);

        Livewire::actingAs($this->admin())
            ->test(ComputersIndex::class)
            ->set('showTrashed', true)
            ->call('restore', $computer->id);
        $this->assertNull($computer->fresh()->deleted_at);
    }

    public function test_show_page_surfaces_health_warnings(): void
    {
        $computer = Computer::factory()->create([
            'last_seen_at'     => now()->subDays(3),
            'secure_boot'      => false,
            'tpm_enabled'      => null,
            'disk_total_bytes' => 500 * 1024 ** 3,
            'disk_free_bytes'  => 20 * 1024 ** 3, // 4% free
        ]);
        \App\Models\DeploymentJob::factory()->failed()->create(['computer_id' => $computer->id]);

        $this->actingAs($this->admin())
            ->get("/computers/{$computer->id}")
            ->assertOk()
            ->assertSee('Attention required')
            ->assertSee('Offline for')
            ->assertSee('Low disk space')
            ->assertSee('Secure Boot is disabled')
            ->assertSee('TPM state unknown')
            ->assertSee('failed deployment');
    }

    public function test_healthy_computer_shows_no_issues(): void
    {
        $computer = Computer::factory()->online()->create([
            'secure_boot'      => true,
            'tpm_enabled'      => true,
            'disk_total_bytes' => 500 * 1024 ** 3,
            'disk_free_bytes'  => 300 * 1024 ** 3,
        ]);

        $this->actingAs($this->admin())
            ->get("/computers/{$computer->id}")
            ->assertOk()
            ->assertSee('No issues detected')
            ->assertSee('40% used'); // disk meter
    }

    public function test_show_page_lists_recent_deployments_and_stats(): void
    {
        $computer = Computer::factory()->create();
        $package = \App\Models\Package::factory()->create(['name' => 'History Pkg']);
        \App\Models\DeploymentJob::factory()->succeeded()->create(['computer_id' => $computer->id, 'package_id' => $package->id]);
        \App\Models\DeploymentJob::factory()->create(['computer_id' => $computer->id]); // pending

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertViewHas('stats', fn ($stats) => $stats['succeeded'] === 1 && $stats['in_flight'] === 1)
            ->assertSee('History Pkg')
            ->assertSee('Recent deployments');
    }

    public function test_show_page_lists_software_with_search_and_managed_badge(): void
    {
        $computer = Computer::factory()->create();
        \App\Models\ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Random Tool', 'source' => 'registry',
        ]);
        $package = \App\Models\Package::factory()->create(['winget_id' => 'Git.Git']);
        \App\Models\ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Git.Git', 'version' => '2.46.0', 'source' => 'winget',
        ]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertSee('Installed software')
            ->assertSee('1 managed · 2 detected')
            // Catalogue-only is the default view:
            ->assertSee('Git.Git')
            ->assertSee('managed')
            ->assertDontSee('Random Tool')
            // Switching to All reveals the full inventory:
            ->set('softwareFilter', 'all')
            ->assertSee('Random Tool')
            ->assertSee('Git.Git')
            ->set('softwareSearch', 'Random')
            ->assertSee('Random Tool')
            ->assertDontSee('Git.Git');
    }

    public function test_software_can_be_filtered_to_what_piodeploy_installed(): void
    {
        $computer = Computer::factory()->create();

        $deployed = \App\Models\Package::factory()->create(['winget_id' => 'Google.Chrome']);
        $notDeployed = \App\Models\Package::factory()->create(['winget_id' => 'Mozilla.Firefox']);

        foreach (['Google.Chrome', 'Mozilla.Firefox'] as $id) {
            \App\Models\ComputerSoftware::factory()->create([
                'computer_id' => $computer->id, 'name' => $id, 'source' => 'winget',
            ]);
        }

        // Only Chrome has a succeeded job — Firefox is in the catalogue but
        // arrived some other way.
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $computer->id,
            'package_id'  => $deployed->id,
            'action'      => \App\Enums\JobAction::Install,
            'status'      => \App\Enums\JobStatus::Succeeded,
        ]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertSee('1 by PioDeploy · 2 managed · 2 detected')
            ->set('softwareFilter', 'deployed')
            ->assertSee('Google.Chrome')
            ->assertDontSee('Mozilla.Firefox');
    }

    public function test_a_failed_job_does_not_claim_piodeploy_installed_it(): void
    {
        $computer = Computer::factory()->create();
        $package = \App\Models\Package::factory()->create(['winget_id' => 'Google.Chrome']);
        \App\Models\ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Google.Chrome', 'source' => 'winget',
        ]);
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $computer->id,
            'package_id'  => $package->id,
            'action'      => \App\Enums\JobAction::Install,
            'status'      => \App\Enums\JobStatus::Failed,
        ]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertSee('0 by PioDeploy')
            ->set('softwareFilter', 'deployed')
            ->assertSee('No PioDeploy installs recorded on this machine yet');
    }

    /** The exact trap this fleet hit: an old agent reports no winget rows. */
    public function test_an_empty_catalogue_view_blames_an_old_agent_when_that_is_the_cause(): void
    {
        $computer = Computer::factory()->create(['agent_version' => '1.2.0']);
        \App\Models\ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Some App', 'source' => 'registry',
        ]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertSee('Agents before 1.3.1 cannot scan winget as SYSTEM');
    }

    public function test_a_current_agent_is_not_blamed_for_an_empty_catalogue(): void
    {
        $computer = Computer::factory()->create(['agent_version' => '1.3.1']);
        \App\Models\ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Some App', 'source' => 'registry',
        ]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertDontSee('Agents before 1.3.1 cannot scan winget as SYSTEM');
    }

    public function test_menu_shows_computers_for_permitted_users(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertSee('Computers');
    }
}
