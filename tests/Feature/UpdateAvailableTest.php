<?php

namespace Tests\Feature;

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
}
