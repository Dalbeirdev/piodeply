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

    public function test_menu_shows_packages_for_permitted_users(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertSee('Packages');
    }
}
