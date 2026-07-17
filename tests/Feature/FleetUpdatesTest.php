<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Dashboard;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\FleetUpdateService;
use App\Services\PackageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Newer exists" has two sources: the machine's own package manager for
 * winget/choco, and the catalogue's pinned latest for binaries nobody can ask.
 * Both are compared, never trusted.
 */
class FleetUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function service(): FleetUpdateService
    {
        return app(FleetUpdateService::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function installed(Computer $computer, string $id, ?string $version, ?string $available = null): void
    {
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => $id,
            'version' => $version, 'available_version' => $available, 'source' => 'winget',
        ]);
    }

    private function chrome(): Package
    {
        return Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
    }

    /* ─────────── what counts ─────────── */

    public function test_a_version_the_machine_says_is_newer_counts(): void
    {
        $this->chrome();
        $this->installed(Computer::factory()->create(), 'Google.Chrome', '138.0', '141.0');

        $pending = $this->service()->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('Google Chrome', $pending->first()['name']);
        $this->assertSame('138.0', $pending->first()['from']);
        $this->assertSame('141.0', $pending->first()['to']);
    }

    /** The old check used !=, so a machine running ahead read as behind. */
    public function test_a_machine_running_newer_than_the_catalogue_is_not_behind(): void
    {
        $package = $this->chrome();
        app(PackageService::class)->addVersion($package, ['version' => '138.0']);

        $this->installed(Computer::factory()->create(), 'Google.Chrome', '141.0');

        $this->assertCount(0, $this->service()->pending());
    }

    public function test_the_catalogue_answers_when_the_machine_cannot(): void
    {
        $package = $this->chrome();
        app(PackageService::class)->addVersion($package, ['version' => '141.0']);

        $this->installed(Computer::factory()->create(), 'Google.Chrome', '138.0'); // no available_version

        $pending = $this->service()->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('catalogue', $pending->first()['source']);
    }

    /** The agent asked the source; the catalogue is a curated guess. */
    public function test_the_machines_own_answer_beats_the_catalogue(): void
    {
        $package = $this->chrome();
        app(PackageService::class)->addVersion($package, ['version' => '140.0']);

        $this->installed(Computer::factory()->create(), 'Google.Chrome', '138.0', '141.0');

        $pending = $this->service()->pending();

        $this->assertSame('141.0', $pending->first()['to']);
        $this->assertSame('agent', $pending->first()['source']);
    }

    public function test_an_offered_version_that_is_not_newer_is_ignored(): void
    {
        $this->chrome();
        $this->installed(Computer::factory()->create(), 'Google.Chrome', '141.0', '141.0');

        $this->assertCount(0, $this->service()->pending());
    }

    public function test_software_with_no_known_version_is_not_guessed_at(): void
    {
        $this->chrome();
        $this->installed(Computer::factory()->create(), 'Google.Chrome', null, '141.0');

        $this->assertCount(0, $this->service()->pending());
    }

    /* ─────────── folding ─────────── */

    public function test_one_update_across_many_machines_is_one_row(): void
    {
        $this->chrome();

        foreach (range(1, 3) as $i) {
            $this->installed(Computer::factory()->create(), 'Google.Chrome', '138.0', '141.0');
        }

        $byPackage = $this->service()->byPackage();

        $this->assertCount(1, $byPackage);
        $this->assertSame(3, $byPackage->first()['machines']);
    }

    public function test_the_oldest_still_out_there_and_the_newest_on_offer(): void
    {
        $this->chrome();
        $this->installed(Computer::factory()->create(), 'Google.Chrome', '138.0', '141.0');
        $this->installed(Computer::factory()->create(), 'Google.Chrome', '140.0', '142.0');

        $row = $this->service()->byPackage()->first();

        $this->assertSame('138.0', $row['from']);
        $this->assertSame('142.0', $row['to']);
    }

    public function test_the_most_widespread_update_leads(): void
    {
        $this->chrome();
        Package::factory()->create(['name' => 'Mozilla Firefox', 'winget_id' => 'Mozilla.Firefox']);

        $this->installed(Computer::factory()->create(), 'Mozilla.Firefox', '130.0', '131.0');
        foreach (range(1, 2) as $i) {
            $this->installed(Computer::factory()->create(), 'Google.Chrome', '138.0', '141.0');
        }

        $this->assertSame('Google Chrome', $this->service()->byPackage()->first()['name']);
    }

    /* ─────────── tenancy ─────────── */

    public function test_it_can_be_scoped_to_one_client(): void
    {
        $this->chrome();

        $mine = Client::factory()->create();
        $mineComputer = Computer::factory()->create([
            'project_id' => Project::factory()->create(['client_id' => $mine->id])->id,
        ]);
        $theirComputer = Computer::factory()->create();

        $this->installed($mineComputer, 'Google.Chrome', '138.0', '141.0');
        $this->installed($theirComputer, 'Google.Chrome', '138.0', '141.0');

        $this->assertCount(2, $this->service()->pending());
        $this->assertCount(1, $this->service()->pending($mine->id));
    }

    /* ─────────── the dashboard ─────────── */

    public function test_the_dashboard_counts_updates_and_the_machines_behind(): void
    {
        $this->chrome();
        $a = Computer::factory()->create();
        $this->installed($a, 'Google.Chrome', '138.0', '141.0');
        $this->installed(Computer::factory()->create(), 'Google.Chrome', '139.0', '141.0');

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($s) => $s['outdated'] === 2 && $s['outdated_machines'] === 2)
            ->assertSee('Updates available')
            ->assertSee('on 2 machines');
    }

    public function test_the_dashboard_lists_what_is_waiting(): void
    {
        $this->chrome();
        $this->installed(Computer::factory()->create(), 'Google.Chrome', '138.0', '141.0');

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertSee('Updates waiting')
            ->assertSee('Google Chrome')
            ->assertSee('141.0');
    }

    public function test_a_fleet_that_is_current_says_nothing(): void
    {
        $this->chrome();
        $this->installed(Computer::factory()->create(), 'Google.Chrome', '141.0');

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($s) => $s['outdated'] === 0)
            ->assertDontSee('Updates waiting');
    }
}
