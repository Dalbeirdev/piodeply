<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Packages\PackageShow;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A winget package has no version PioDeploy owns — the source resolves one
 * at install. "auto" was honest and useless; the agents already report
 * what they found, so the page answers the real questions instead: what is
 * out there, what is newest, and how many machines are behind.
 */
class PackageFleetVersionTest extends TestCase
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

    private function reportSoftware(Computer $computer, string $version, ?string $available = null): void
    {
        ComputerSoftware::create([
            'computer_id'       => $computer->id,
            'name'              => 'Mozilla.Firefox',
            'version'           => $version,
            'available_version' => $available,
            'source'            => 'winget',
        ]);
    }

    public function test_the_page_shows_real_versions_instead_of_auto(): void
    {
        $package = Package::factory()->create(['name' => 'Mozilla Firefox', 'winget_id' => 'Mozilla.Firefox']);

        $current = Computer::factory()->create();
        $behind = Computer::factory()->create();
        $this->reportSoftware($current, '141.0.2');
        $this->reportSoftware($behind, '139.0', available: '141.0.2');

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->assertOk()
            ->assertDontSee('auto — winget resolves at install')
            ->assertSee('141.0.2')            // newest seen on the fleet
            ->assertSee('139.0')              // and the spread behind it
            ->assertSee('1 with an update waiting');
    }

    public function test_versions_are_ordered_and_counted_per_machine(): void
    {
        $package = Package::factory()->create(['winget_id' => 'Mozilla.Firefox']);

        foreach (['141.0.2', '141.0.2', '139.0'] as $version) {
            $this->reportSoftware(Computer::factory()->create(), $version);
        }

        $fleet = Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->viewData('fleet');

        $this->assertSame('141.0.2', $fleet['latest']);
        $this->assertSame(['141.0.2' => 2, '139.0' => 1], $fleet['installed']->all());
        $this->assertSame(0, $fleet['outdated'], 'nothing is offering an upgrade here');
    }

    public function test_a_package_nobody_has_installed_says_so_plainly(): void
    {
        $package = Package::factory()->create(['winget_id' => 'Nobody.HasThis']);

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->assertSee('not seen yet');
    }

    public function test_a_tenants_fleet_view_is_their_own_fleet(): void
    {
        $package = Package::factory()->create(['winget_id' => 'Mozilla.Firefox']);

        $mine = Client::factory()->create();
        $myMachine = Computer::factory()->create([
            'project_id' => Project::factory()->create(['client_id' => $mine->id])->id,
        ]);
        $this->reportSoftware($myMachine, '139.0');
        // Another customer runs a much newer build — irrelevant here.
        $this->reportSoftware(Computer::factory()->create(), '141.0.2');

        $owner = tap(User::factory()->create(['client_id' => $mine->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        $fleet = Livewire::actingAs($owner)
            ->test(PackageShow::class, ['package' => $package])
            ->viewData('fleet');

        $this->assertSame('139.0', $fleet['latest'], "another tenant's versions must not leak in");
        $this->assertSame(1, $fleet['tracked']);
    }
}
