<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Dashboard;
use App\Models\Client;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\PackageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_tiles_reflect_fleet_and_job_state(): void
    {
        $client = Client::factory()->create(['company_name' => 'Tile Corp']);
        $project = Project::factory()->for($client)->create();
        $online = Computer::factory()->for($project)->online()->count(2)->create();
        Computer::factory()->for($project)->offline()->create();
        DeploymentJob::factory()->create(['computer_id' => $online[0]->id]);            // pending
        DeploymentJob::factory()->failed()->create(['computer_id' => $online[0]->id]);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($stats) => $stats['online'] === 2
                && $stats['offline'] === 1
                && $stats['pending'] === 1
                && $stats['failed'] === 1
                && $stats['clients'] === Client::count()
                && $stats['projects'] === Project::count())
            ->assertSee('Computers online')
            ->assertSee('Failed jobs')
            ->assertSee('Tile Corp'); // fleet-by-client chart row
    }

    public function test_outdated_software_compares_against_pinned_latest(): void
    {
        $package = Package::factory()->create(['winget_id' => 'Vendor.App']);
        app(PackageService::class)->addVersion($package, ['version' => '2.0.0']);

        $computer = Computer::factory()->create();
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Vendor.App', 'version' => '1.0.0', 'source' => 'winget',
        ]);
        // Same version -> not outdated
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id, 'name' => 'Vendor.App', 'version' => '2.0.0', 'source' => 'winget',
        ]);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($stats) => $stats['outdated'] === 1);
    }

    public function test_license_usage_counts_commercial_installs(): void
    {
        $commercial = Package::factory()->create(['winget_id' => 'Paid.App', 'license' => 'Commercial']);
        Package::factory()->create(['winget_id' => 'Free.App', 'license' => 'MIT']);
        $computer = Computer::factory()->create();
        ComputerSoftware::factory()->create(['computer_id' => $computer->id, 'name' => 'Paid.App', 'source' => 'winget']);
        ComputerSoftware::factory()->create(['computer_id' => $computer->id, 'name' => 'Free.App', 'source' => 'winget']);

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('stats', fn ($stats) => $stats['licenses'] === 1);
    }

    public function test_deployment_series_covers_14_days_and_counts_statuses(): void
    {
        DeploymentJob::factory()->succeeded()->count(2)->create();
        DeploymentJob::factory()->failed()->create();

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertViewHas('series', function ($series) {
                $today = collect($series)->last();

                return count($series) === 14
                    && $today['succeeded'] === 2
                    && $today['failed'] === 1;
            });
    }

    public function test_dashboard_shows_recent_activity(): void
    {
        Client::factory()->create(['company_name' => 'Audit Trail Co']); // generates activity

        Livewire::actingAs($this->admin())
            ->test(Dashboard::class)
            ->assertSee('Recent activity')
            ->assertSee('created');
    }
}
