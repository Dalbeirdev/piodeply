<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Packages\PackageForm;
use App\Livewire\Packages\PackageShow;
use App\Livewire\Packages\PackagesIndex;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\User;
use App\Services\PackageService;
use Database\Seeders\PackagesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackageRepositoryTest extends TestCase
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

    private function technician(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Technician->value));
    }

    public function test_pages_are_permission_gated(): void
    {
        $package = Package::factory()->create();

        $this->get('/packages')->assertRedirect('/login');

        // Technician: packages.view but not packages.manage
        $this->actingAs($this->technician())->get('/packages')->assertOk();
        $this->actingAs($this->technician())->get('/packages/create')->assertForbidden();
        $this->actingAs($this->technician())->get("/packages/{$package->id}")->assertOk();

        $this->actingAs($this->admin())->get("/packages/{$package->id}/edit")->assertOk();
    }

    public function test_seeder_is_idempotent_and_valid(): void
    {
        $this->seed(PackagesSeeder::class);
        $packageCount = Package::count();
        $this->assertGreaterThan(15, $packageCount);

        $this->seed(PackagesSeeder::class);
        $this->assertSame($packageCount, Package::count());

        Package::all()->each(function (Package $package) {
            $this->assertNotNull($package->winget_id);
            $this->assertMatchesRegularExpression(Package::ID_PATTERN, $package->winget_id);
        });
    }

    public function test_admin_creates_winget_package(): void
    {
        $category = PackageCategory::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(PackageForm::class)
            ->set('package_category_id', $category->id)
            ->set('name', 'Paint.NET')
            ->set('vendor', 'dotPDN')
            ->set('installer_type', 'winget')
            ->set('winget_id', 'dotPDN.PaintDotNet')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('packages', ['name' => 'Paint.NET', 'winget_id' => 'dotPDN.PaintDotNet']);
        $this->assertNotEmpty(Package::where('name', 'Paint.NET')->first()->slug);
    }

    public function test_winget_package_requires_valid_id(): void
    {
        $category = PackageCategory::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(PackageForm::class)
            ->set('package_category_id', $category->id)
            ->set('name', 'Evil App')
            ->set('installer_type', 'winget')
            ->set('winget_id', 'Evil; Remove-Item C:\\')
            ->call('save')
            ->assertHasErrors(['winget_id']);

        Livewire::actingAs($this->admin())
            ->test(PackageForm::class)
            ->set('package_category_id', $category->id)
            ->set('name', 'No Id App')
            ->set('installer_type', 'winget')
            ->call('save')
            ->assertHasErrors(['winget_id']);
    }

    public function test_binary_version_requires_https_url_and_sha256(): void
    {
        $package = Package::factory()->msi()->create();

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->set('version', '1.0.0')
            ->set('installer_url', 'http://insecure.example.com/x.msi')
            ->set('sha256', str_repeat('a', 64))
            ->call('addVersion')
            ->assertHasErrors(['installer_url']);

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->set('version', '1.0.0')
            ->set('installer_url', 'https://example.com/x.msi')
            ->set('sha256', 'not-a-hash')
            ->call('addVersion')
            ->assertHasErrors(['sha256']);

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->set('version', '1.0.0')
            ->set('installer_url', 'https://example.com/x.msi')
            ->set('sha256', strtoupper(str_repeat('ab', 32)))
            ->set('silent_args', '/qn /norestart')
            ->call('addVersion')
            ->assertHasNoErrors();

        $version = $package->versions()->first();
        $this->assertSame(str_repeat('ab', 32), $version->sha256, 'sha256 normalised to lowercase');
        $this->assertTrue($version->is_latest);
    }

    public function test_adding_a_version_demotes_the_previous_latest(): void
    {
        $package = Package::factory()->msi()->create();
        $service = app(PackageService::class);

        $v1 = $service->addVersion($package, [
            'version' => '1.0.0', 'installer_url' => 'https://x.test/1.msi', 'sha256' => str_repeat('a', 64),
        ]);
        $v2 = $service->addVersion($package, [
            'version' => '2.0.0', 'installer_url' => 'https://x.test/2.msi', 'sha256' => str_repeat('b', 64),
        ]);

        $this->assertFalse($v1->fresh()->is_latest);
        $this->assertTrue($v2->fresh()->is_latest);
        $this->assertTrue($package->latestVersion()->first()->is($v2));
        $this->assertSame(1, $package->versions()->where('is_latest', true)->count());
    }

    public function test_removing_the_latest_version_promotes_the_previous(): void
    {
        $package = Package::factory()->msi()->create();
        $service = app(PackageService::class);
        $v1 = $service->addVersion($package, ['version' => '1.0.0', 'installer_url' => 'https://x.test/1.msi', 'sha256' => str_repeat('a', 64)]);
        $v2 = $service->addVersion($package, ['version' => '2.0.0', 'installer_url' => 'https://x.test/2.msi', 'sha256' => str_repeat('b', 64)]);

        $service->removeVersion($v2);

        $this->assertTrue($v1->fresh()->is_latest);
        $this->assertSame(1, $package->versions()->count());
    }

    public function test_winget_packages_do_not_require_binary_fields(): void
    {
        $package = Package::factory()->create(); // winget type

        $version = app(PackageService::class)->addVersion($package, ['version' => '126.0']);

        $this->assertNull($version->installer_url);
        $this->assertTrue($version->is_latest);
    }

    public function test_technician_cannot_manage_packages(): void
    {
        $package = Package::factory()->create();

        Livewire::actingAs($this->technician())
            ->test(PackagesIndex::class)
            ->call('toggleActive', $package->id)
            ->assertForbidden();

        Livewire::actingAs($this->technician())
            ->test(PackageShow::class, ['package' => $package])
            ->set('version', '1.0')
            ->call('addVersion')
            ->assertForbidden();
    }

    public function test_search_and_filters(): void
    {
        $this->seed(PackagesSeeder::class);
        Package::factory()->msi()->inactive()->create(['name' => 'Legacy MSI Tool']);

        Livewire::actingAs($this->admin())
            ->test(PackagesIndex::class)
            ->set('search', 'chrome')
            ->assertSee('Google Chrome')
            ->assertDontSee('Legacy MSI Tool')
            ->set('search', '')
            ->set('installerType', 'msi')
            ->assertSee('Legacy MSI Tool')
            ->assertDontSee('Google Chrome')
            ->set('installerType', '')
            ->set('activeOnly', true)
            ->assertDontSee('Legacy MSI Tool');
    }

    /**
     * The exact gap PackageMode was built to close (its own docblock:
     * deactivating hid a package entirely, so "active" alone used to be the
     * whole story). An OS-managed/Store package is still active, but a
     * click on it does nothing -- the old badge could not tell the two
     * apart and this list must not say "active" for both.
     */
    public function test_an_active_but_blocked_package_never_shows_the_plain_active_badge(): void
    {
        Package::factory()->create([
            'name' => 'Microsoft Edge', 'is_active' => true, 'management_mode' => \App\Enums\PackageMode::OsManaged,
        ]);

        Livewire::actingAs($this->admin())
            ->test(PackagesIndex::class)
            ->assertSee('OS-managed')
            // Not assertDontSee('active') -- "Deactivate" and "Active only"
            // both contain that substring. The actual badge markup does not.
            ->assertDontSeeHtml('<span class="pd-dot"></span>active</span>');
    }

    public function test_a_deployable_active_package_still_shows_the_plain_active_badge(): void
    {
        Package::factory()->create(['name' => 'Notepad++', 'is_active' => true, 'management_mode' => \App\Enums\PackageMode::Deploy]);

        Livewire::actingAs($this->admin())
            ->test(PackagesIndex::class)
            ->assertSeeHtml('<span class="pd-dot"></span>active</span>');
    }

    public function test_summary_cards_count_by_management_status(): void
    {
        Package::factory()->create(['is_active' => true, 'management_mode' => \App\Enums\PackageMode::Deploy]);
        Package::factory()->create(['is_active' => true, 'management_mode' => \App\Enums\PackageMode::Store]);
        Package::factory()->create(['is_active' => false]);

        Livewire::actingAs($this->admin())
            ->test(PackagesIndex::class)
            ->assertViewHas('stats', fn (array $s) => $s['total'] === 3
                && $s['deployable'] === 1
                && $s['blocked'] === 1
                && $s['inactive'] === 1);
    }

    public function test_clicking_the_blocked_card_filters_to_active_but_undeployable_packages(): void
    {
        Package::factory()->create([
            'name' => 'BlockedPkg', 'is_active' => true, 'management_mode' => \App\Enums\PackageMode::Unsupported,
        ]);
        Package::factory()->create(['name' => 'DeployablePkg', 'is_active' => true]);

        Livewire::actingAs($this->admin())
            ->test(PackagesIndex::class)
            ->assertSee('BlockedPkg')->assertSee('DeployablePkg')
            ->set('managementStatus', 'blocked')
            ->assertSee('BlockedPkg')->assertDontSee('DeployablePkg');
    }

    public function test_package_changes_are_activity_logged(): void
    {
        $package = Package::factory()->create(['name' => 'Logged Package']);

        $this->assertDatabaseHas('activity_log', [
            'log_name'     => 'packages',
            'subject_type' => Package::class,
            'subject_id'   => $package->id,
            'description'  => 'created',
        ]);
    }

    public function test_show_page_displays_fleet_stats_and_recent_jobs(): void
    {
        $package = Package::factory()->create();
        $computerA = \App\Models\Computer::factory()->create(['hostname' => 'STATS-PC-A']);
        $computerB = \App\Models\Computer::factory()->create(['hostname' => 'STATS-PC-B']);

        \App\Models\DeploymentJob::factory()->succeeded()->create(['package_id' => $package->id, 'computer_id' => $computerA->id]);
        \App\Models\DeploymentJob::factory()->succeeded()->create(['package_id' => $package->id, 'computer_id' => $computerB->id]);
        \App\Models\DeploymentJob::factory()->create(['package_id' => $package->id, 'computer_id' => $computerA->id]); // pending
        \App\Models\DeploymentJob::factory()->failed()->create(['package_id' => $package->id, 'computer_id' => $computerB->id]);

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->assertViewHas('stats', fn ($stats) => $stats['installed_on'] === 2
                && $stats['in_flight'] === 1
                && $stats['failed'] === 1
                && $stats['last_deploy'] !== null)
            ->assertSee('STATS-PC-A')
            ->assertSee('Recent deployments');
    }

    public function test_quick_deploy_from_package_page_queues_job(): void
    {
        $package = Package::factory()->create();
        $computer = \App\Models\Computer::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->set('deploy_computer_id', $computer->id)
            ->set('deploy_action', 'install')
            ->set('deploy_priority', 3)
            ->call('deploy')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deployment_jobs', [
            'package_id'  => $package->id,
            'computer_id' => $computer->id,
            'action'      => 'install',
            'priority'    => 3,
        ]);
    }

    public function test_viewer_cannot_quick_deploy(): void
    {
        $package = Package::factory()->create();
        $computer = \App\Models\Computer::factory()->create();
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        Livewire::actingAs($viewer)
            ->test(PackageShow::class, ['package' => $package])
            ->set('deploy_computer_id', $computer->id)
            ->call('deploy')
            ->assertForbidden();
    }

    public function test_menu_shows_packages_for_permitted_users(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertSee('Packages');
    }
}
