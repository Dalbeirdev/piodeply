<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\PolicyAction;
use App\Enums\PolicyMode;
use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputerShow;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\Package;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\ProjectService;
use App\DTOs\ProjectData;
use App\Models\Client;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Only the machine's package manager knows a newer version exists — the
 * server has the catalogue and what is installed, and no idea Chrome shipped
 * a release this morning. So an Install policy calls a two-year-old build
 * "Compliant", which is true and useless.
 */
class UpdateAvailableTest extends TestCase
{
    use RefreshDatabase;

    private Computer $computer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->computer = Computer::factory()->create();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function page()
    {
        return Livewire::actingAs($this->admin())->test(ComputerShow::class, ['computer' => $this->computer]);
    }

    private function chrome(): Package
    {
        return Package::factory()->create([
            'name' => 'Google Chrome', 'installer_type' => 'winget', 'winget_id' => 'Google.Chrome',
        ]);
    }

    private function installed(string $version, ?string $available): ComputerSoftware
    {
        return ComputerSoftware::factory()->create([
            'computer_id'       => $this->computer->id,
            'name'              => 'Google.Chrome',
            'version'           => $version,
            'available_version' => $available,
            'source'            => 'winget',
        ]);
    }

    /* ─────────── the model's own judgement ─────────── */

    public function test_update_now_adopts_an_uncatalogued_app_then_queues_it(): void
    {
        // An outdated winget app the catalogue has never heard of.
        ComputerSoftware::create([
            'computer_id'       => $this->computer->id,
            'name'              => 'Google.Chrome',
            'version'           => '150.0.7871.182',
            'available_version' => '150.0.7871.187',
            'publisher'         => 'Google LLC',
            'source'            => 'winget',
        ]);
        $this->assertDatabaseMissing('packages', ['winget_id' => 'Google.Chrome']);

        Livewire::actingAs($this->admin())
            ->test(ComputerShow::class, ['computer' => $this->computer])
            ->call('queueUpdate', $this->computer->software()->first()->id);

        // Adopted into the catalogue...
        $package = Package::where('winget_id', 'Google.Chrome')->first();
        $this->assertNotNull($package);
        $this->assertSame('Google LLC', $package->vendor);
        // ...and an update job queued in the same click.
        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $this->computer->id,
            'package_id'  => $package->id,
            'action'      => 'update',
        ]);
    }

    public function test_update_now_refuses_software_with_no_manager_id(): void
    {
        // A control-panel entry with no winget/choco id cannot be managed.
        ComputerSoftware::create([
            'computer_id'       => $this->computer->id,
            'name'              => 'Some Bespoke Tool',
            'version'           => '1.0',
            'available_version' => '2.0',
            'source'            => 'inventory',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ComputerShow::class, ['computer' => $this->computer])
            ->call('queueUpdate', $this->computer->software()->first()->id);

        $this->assertSame(0, Package::count());
        $this->assertSame(0, \App\Models\DeploymentJob::count());
    }

    /**
     * The real bug: deactivating a package to edit it made "Update now"
     * think it did not exist yet, and clone a duplicate under the same
     * winget_id. Reproduced live on Microsoft Edge -- deactivated mid-edit,
     * "Update now" adopted a second "Microsoft.Edge" into General.
     */
    public function test_update_now_never_clones_a_deactivated_package(): void
    {
        $edge = Package::factory()->create([
            'name' => 'Microsoft Edge', 'winget_id' => 'Microsoft.Edge', 'is_active' => false,
        ]);
        ComputerSoftware::create([
            'computer_id' => $this->computer->id, 'name' => 'Microsoft.Edge',
            'version' => '128.0', 'available_version' => '129.0', 'source' => 'winget',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ComputerShow::class, ['computer' => $this->computer])
            ->call('queueUpdate', $this->computer->software()->first()->id)
            ->assertSee('deactivated');

        $this->assertSame(1, Package::where('winget_id', 'Microsoft.Edge')->count(),
            'the deactivated package must be recognised, not cloned');
        $this->assertSame(0, \App\Models\DeploymentJob::where('package_id', $edge->id)->count(),
            'a deactivated package must never receive a queued job either');
    }

    public function test_a_tenants_adopted_package_is_private_to_them(): void
    {
        $client = Client::factory()->create();
        $computer = Computer::factory()->create([
            'project_id' => \App\Models\Project::factory()->create(['client_id' => $client->id])->id,
        ]);
        ComputerSoftware::create([
            'computer_id' => $computer->id, 'name' => 'Mozilla.Firefox',
            'version' => '139.0', 'available_version' => '141.0', 'source' => 'winget',
        ]);
        $owner = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        Livewire::actingAs($owner)
            ->test(ComputerShow::class, ['computer' => $computer])
            ->call('queueUpdate', $computer->software()->first()->id);

        $package = Package::where('winget_id', 'Mozilla.Firefox')->first();
        $this->assertSame($client->id, $package->client_id, 'a customer adopts into their own private catalogue, not the shared one');
    }

    public function test_a_queued_update_shows_an_in_flight_state(): void
    {
        $package = Package::factory()->create(['winget_id' => 'Google.Chrome', 'name' => 'Google Chrome']);
        ComputerSoftware::create([
            'computer_id' => $this->computer->id, 'name' => 'Google.Chrome',
            'version' => '150.0.7871.182', 'available_version' => '150.0.7871.187', 'source' => 'winget',
        ]);
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $this->computer->id, 'package_id' => $package->id,
            'action' => 'update', 'status' => \App\Enums\JobStatus::Pending,
        ]);

        // The row shows "Queued", not another "Update now" button that would
        // double-queue.
        Livewire::actingAs($this->admin())
            ->test(ComputerShow::class, ['computer' => $this->computer])
            ->assertSee('Queued')
            ->assertDontSee('Update now');
    }

    public function test_an_available_version_ahead_of_the_installed_one_is_an_update(): void
    {
        $this->assertTrue($this->installed('138.0.7615.129', '141.0.7390.55')->hasUpdate());
    }

    /** winget occasionally offers an "available" that is not actually ahead. */
    public function test_an_available_version_that_is_not_newer_is_not_an_update(): void
    {
        $this->assertFalse($this->installed('141.0', '141.0')->hasUpdate());
        $this->assertFalse($this->installed('141.0', '140.0')->hasUpdate());
    }

    public function test_nothing_offered_is_not_an_update(): void
    {
        $this->assertFalse($this->installed('141.0', null)->hasUpdate());
    }

    public function test_an_unknown_installed_version_cannot_be_compared(): void
    {
        $this->assertFalse($this->installed('', '141.0')->fresh()->hasUpdate());
    }

    /* ─────────── the page ─────────── */

    public function test_the_software_list_shows_the_version_on_offer(): void
    {
        $this->chrome();
        $this->installed('138.0.7615.129', '141.0.7390.55');

        $this->page()
            ->set('softwareFilter', 'managed') // nothing was deployed by a job here
            ->assertSee('138.0.7615.129')
            ->assertSee('141.0.7390.55')
            ->assertSee('1 outdated');
    }

    public function test_software_can_be_filtered_to_what_is_outdated(): void
    {
        $this->chrome();
        $this->installed('138.0', '141.0');
        ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Mozilla.Firefox',
            'version' => '130.0', 'available_version' => null, 'source' => 'winget',
        ]);

        $this->page()
            ->set('softwareFilter', 'outdated')
            ->assertSee('Google.Chrome')
            ->assertDontSee('Mozilla.Firefox');
    }

    public function test_nothing_outdated_shows_no_count(): void
    {
        $this->chrome();
        $this->installed('141.0', null);

        // Not assertDontSee('outdated') — the filter dropdown contains the
        // word, so that would pass for the wrong reason.
        $this->page()
            ->assertViewHas('softwareOutdated', 0)
            ->assertDontSee('1 outdated');
    }

    /* ─────────── the software summary cards ─────────── */

    public function test_software_can_be_filtered_to_what_is_up_to_date(): void
    {
        $this->chrome();
        $this->installed('138.0', '141.0');
        ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Mozilla.Firefox',
            'version' => '141.0', 'available_version' => null, 'source' => 'winget',
        ]);

        $this->page()
            ->set('softwareFilter', 'uptodate')
            ->assertSee('Mozilla.Firefox')
            ->assertDontSee('Google.Chrome');
    }

    public function test_software_can_be_filtered_to_a_pending_job(): void
    {
        $package = $this->chrome();
        $this->installed('138.0', '141.0');
        ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Mozilla.Firefox',
            'version' => '141.0', 'available_version' => null, 'source' => 'winget',
        ]);
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $this->computer->id, 'package_id' => $package->id,
            'action' => 'update', 'status' => JobStatus::Running,
        ]);

        $this->page()
            ->set('softwareFilter', 'pending')
            ->assertSee('Google.Chrome')
            ->assertDontSee('Mozilla.Firefox');
    }

    /** Up-to-date and outdated must always split the total exactly, since a card row shows both next to it. */
    public function test_up_to_date_and_outdated_counts_add_up_to_the_total(): void
    {
        $this->chrome();
        $this->installed('138.0', '141.0'); // outdated
        ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Mozilla.Firefox',
            'version' => '141.0', 'available_version' => null, 'source' => 'winget',
        ]); // up to date

        $this->page()
            ->assertViewHas('softwareTotal', 2)
            ->assertViewHas('softwareOutdated', 1)
            ->assertViewHas('softwareUpToDate', 1);
    }

    public function test_pending_count_only_counts_winget_rows_with_an_in_flight_job(): void
    {
        $package = $this->chrome();
        $this->installed('138.0', '141.0');
        \App\Models\DeploymentJob::factory()->create([
            'computer_id' => $this->computer->id, 'package_id' => $package->id,
            'action' => 'update', 'status' => JobStatus::Pending,
        ]);

        $this->page()->assertViewHas('softwarePending', 1);
    }

    /* ─────────── the status panel ─────────── */

    /** The exact case on the live fleet: Chrome 138, "Compliant", months old. */
    public function test_a_compliant_install_policy_still_says_a_newer_version_exists(): void
    {
        $package = $this->chrome();
        $this->installed('138.0.7615.129', '141.0.7390.55');

        SoftwarePolicy::factory()->create([
            'project_id' => $this->computer->project_id,
            'package_id' => $package->id,
            'action'     => PolicyAction::Install,
            'mode'       => PolicyMode::Enforce,
        ]);

        $this->page()
            ->assertSee('Installed (138.0.7615.129) — 141.0.7390.55 available');
    }

    /** Present and current must not be nagged at. */
    public function test_a_current_install_policy_says_nothing_extra(): void
    {
        $package = $this->chrome();
        $this->installed('141.0', null);

        SoftwarePolicy::factory()->create([
            'project_id' => $this->computer->project_id,
            'package_id' => $package->id,
            'action'     => PolicyAction::Install,
            'mode'       => PolicyMode::Enforce,
        ]);

        // Asserted on the reason itself: the page also contains the words
        // "Update available" in the filter, so assertDontSee would lie.
        $reason = app(\App\Services\PolicyService::class)
            ->explainFor($this->computer)->first()['reason'];

        $this->assertSame('Installed (141.0)', $reason);
    }

    public function test_a_binary_package_has_no_package_manager_to_ask(): void
    {
        $package = Package::factory()->create([
            'installer_type' => 'msi', 'winget_id' => null, 'choco_id' => null,
        ]);

        SoftwarePolicy::factory()->create([
            'project_id' => $this->computer->project_id,
            'package_id' => $package->id,
            'action'     => PolicyAction::Install,
            'mode'       => PolicyMode::Enforce,
        ]);

        // Must not blow up looking for a manager row that cannot exist.
        $this->page()->assertOk();
    }

    /* ─────────── the wire ─────────── */

    public function test_the_agent_can_report_an_available_version(): void
    {
        $result = app(ProjectService::class)->create(new ProjectData(
            clientId: Client::factory()->create()->id, name: 'Fleet',
        ));
        $computer = Computer::factory()->create(['project_id' => $result['project']->id]);

        $this->postJson('/api/v1/agent/software', [
            'agent_uuid' => $computer->agent_uuid,
            'software'   => [
                ['name' => 'Google.Chrome', 'version' => '138.0', 'available_version' => '141.0', 'source' => 'winget'],
            ],
        ], ['X-Api-Key' => $result['plain_api_key'], 'Accept' => 'application/json'])->assertOk();

        $this->assertSame('141.0', ComputerSoftware::where('computer_id', $computer->id)->sole()->available_version);
    }

    public function test_an_agent_older_than_1_3_3_still_reports_inventory(): void
    {
        $result = app(ProjectService::class)->create(new ProjectData(
            clientId: Client::factory()->create()->id, name: 'Fleet',
        ));
        $computer = Computer::factory()->create(['project_id' => $result['project']->id]);

        $this->postJson('/api/v1/agent/software', [
            'agent_uuid' => $computer->agent_uuid,
            'software'   => [['name' => 'Google.Chrome', 'version' => '138.0', 'source' => 'winget']],
        ], ['X-Api-Key' => $result['plain_api_key'], 'Accept' => 'application/json'])->assertOk();

        $row = ComputerSoftware::where('computer_id', $computer->id)->sole();
        $this->assertSame('138.0', $row->version);
        $this->assertNull($row->available_version);
    }

    /* ── The Action1-style status column + one-click update ───────────── */

    public function test_the_table_shows_update_required_and_status_columns(): void
    {
        $this->chrome();
        $this->installed('138.0', '141.0');
        ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Mozilla.Firefox',
            'version' => '130.0', 'available_version' => null, 'source' => 'winget',
        ]);

        $this->page()
            ->set('softwareFilter', 'all')
            ->assertSee('Update required')
            ->assertSee('Update now')     // outdated + managed → actionable
            ->assertSee('Up to date');    // current row → green status
    }

    public function test_update_now_queues_an_update_job_through_the_guarded_path(): void
    {
        $this->chrome();
        $row = $this->installed('138.0', '141.0');

        $this->page()
            ->set('softwareFilter', 'all')
            ->call('queueUpdate', $row->id);

        $this->assertDatabaseHas('deployment_jobs', [
            'computer_id' => $this->computer->id, 'action' => 'update', 'status' => 'pending',
        ]);

        // Clicking again while the job is in flight does not duplicate it.
        $this->page()->call('queueUpdate', $row->id);
        $this->assertSame(1, \App\Models\DeploymentJob::count());
    }

    public function test_update_now_on_an_uncatalogued_winget_row_now_adopts_and_queues(): void
    {
        // Behaviour changed: an uncatalogued winget app is no longer a dead
        // end — Update now adopts it into the catalogue and queues the job.
        $row = ComputerSoftware::factory()->create([
            'computer_id' => $this->computer->id, 'name' => 'Some.RandomApp',
            'version' => '1.0', 'available_version' => '2.0', 'source' => 'winget',
        ]);

        $this->page()
            ->set('softwareFilter', 'all')
            ->call('queueUpdate', $row->id);

        $this->assertSame(1, Package::where('winget_id', 'Some.RandomApp')->count());
        $this->assertSame(1, \App\Models\DeploymentJob::where('action', 'update')->count());
    }
}
