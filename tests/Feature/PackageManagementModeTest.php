<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\PackageMode;
use App\Enums\PolicyMode;
use App\Enums\QueueOutcome;
use App\Enums\Role as RoleEnum;
use App\Livewire\Packages\PackageForm;
use App\Livewire\Packages\PackageShow;
use App\Models\PackageCategory;
use App\Models\Client;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\PolicyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "active/inactive" answered one question and got conflated: a package that
 * cannot be installed THIS WAY (Edge — Windows owns it; Teams — it's an
 * MSIX, per-user by design) is not the same as one that should not exist in
 * the catalogue. management_mode separates them, and every path that can
 * create a deployment job must agree on it, or a package "removed" from one
 * route reappears through another.
 *
 * These tests also pin the real incident found while building this: a
 * policy (id irrelevant here, #3 in production) targeting a package that had
 * been deactivated. The batch policy path had NO pre-check at all, so the
 * scheduled run was one bad package away from throwing on every computer
 * behind it, in every OTHER policy queued after it in the same run.
 */
class PackageManagementModeTest extends TestCase
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

    private function computer(): Computer
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        return Computer::factory()->create(['project_id' => $project->id]);
    }

    private function edgeLikePackage(): Package
    {
        return Package::factory()->create(['name' => 'Microsoft Edge', 'management_mode' => PackageMode::OsManaged]);
    }

    // ── The model ──────────────────────────────────────────────────────

    public function test_a_deploy_mode_package_is_deployable(): void
    {
        $package = Package::factory()->create(['management_mode' => PackageMode::Deploy]);

        $this->assertTrue($package->isDeployable());
        $this->assertNull($package->blockedReason());
    }

    public function test_every_new_migration_defaults_existing_rows_to_deploy(): void
    {
        // No management_mode passed — the column's own DB default applies,
        // exactly as it does for every package that existed before this
        // migration ran.
        $package = Package::factory()->create();

        $this->assertTrue($package->management_mode->isDeployable());
    }

    /**
     * The regression this schema change nearly shipped with: Eloquent's
     * create() does not re-fetch the row, so relying on the DB column
     * default alone left the IN-MEMORY object's management_mode null right
     * up until a fresh() — meaning a package created and immediately shown
     * (the normal "create → redirect to show" flow) would crash the moment
     * isDeployable() ran, in that same request, before ever touching the
     * database default. Caught by this test, fixed with a model-level
     * $attributes default.
     */
    public function test_a_freshly_created_package_is_usable_without_reloading_it(): void
    {
        $package = Package::factory()->create(); // no ->fresh() — exactly as a controller would use it

        $this->assertTrue($package->isDeployable());
        $this->assertNull($package->blockedReason());
    }

    public function test_an_os_managed_package_explains_itself_rather_than_vanishing(): void
    {
        $package = $this->edgeLikePackage();

        $this->assertFalse($package->isDeployable());
        $this->assertStringContainsString('OS-managed', $package->blockedReason());
        $this->assertStringContainsString('Windows', $package->blockedReason());
    }

    public function test_a_store_package_names_the_real_reason(): void
    {
        $package = Package::factory()->create(['name' => 'Microsoft Teams', 'management_mode' => PackageMode::Store]);

        $this->assertStringContainsString('Store', $package->blockedReason());
    }

    public function test_deactivated_still_blocks_regardless_of_mode(): void
    {
        $package = Package::factory()->create(['is_active' => false, 'management_mode' => PackageMode::Deploy]);

        $this->assertFalse($package->isDeployable());
    }

    // ── Direct queue() — PackageShow's funnel, and PolicyService's ──────

    public function test_queue_refuses_a_non_deployable_package(): void
    {
        $package = $this->edgeLikePackage();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('OS-managed');

        app(DeploymentService::class)->queue(
            computer: $this->computer(), package: $package, action: JobAction::Update,
        );
    }

    public function test_queue_still_works_normally_for_an_ordinary_package(): void
    {
        $job = app(DeploymentService::class)->queue(
            computer: $this->computer(), package: Package::factory()->create(), action: JobAction::Install,
        );

        $this->assertInstanceOf(DeploymentJob::class, $job);
    }

    // ── queueIfNeeded() / queueBulk() — the click and bulk-click paths ──

    public function test_queue_if_needed_refuses_before_creating_a_job(): void
    {
        $package = $this->edgeLikePackage();

        $result = app(DeploymentService::class)->queueIfNeeded(
            computer: $this->computer(), package: $package, action: JobAction::Update,
        );

        $this->assertSame(QueueOutcome::Invalid, $result->outcome);
        $this->assertStringContainsString('OS-managed', $result->message);
        $this->assertSame(0, DeploymentJob::count());
    }

    /**
     * The bulk path never catches a raw DomainException — it only reads the
     * QueueResult from queueIfNeeded(). If the guard lived only inside
     * queue() and threw, ONE machine hitting it would abort the whole bulk
     * run instead of being tallied as refused.
     */
    public function test_bulk_deploy_tallies_a_non_deployable_package_as_refused_not_a_crash(): void
    {
        $package = $this->edgeLikePackage();
        $computers = Computer::factory()->count(3)->create(['project_id' => Project::factory()->create()->id]);

        $result = app(DeploymentService::class)->queueBulk($computers, $package, JobAction::Update);

        $this->assertSame(3, $result->refused);
        $this->assertSame(0, $result->queued);
        $this->assertSame(0, DeploymentJob::count());
    }

    // ── PolicyService — the real production incident this pins ──────────

    public function test_a_policy_targeting_a_non_deployable_package_is_skipped_not_thrown(): void
    {
        $package = $this->edgeLikePackage();
        $computer = $this->computer();
        $policy = SoftwarePolicy::factory()->create([
            'package_id' => $package->id,
            'project_id' => $computer->project_id,
            'mode'       => PolicyMode::Enforce,
        ]);

        // Must not throw — this is exactly what the live batch run does
        // every five minutes for every enforce-mode policy.
        $queued = app(PolicyService::class)->enforce($policy);

        $this->assertSame(0, $queued);
        $this->assertSame(0, DeploymentJob::count());
    }

    /**
     * The incident, reproduced directly: a bad policy must not take down
     * enforcement for every OTHER policy in the same scheduled run.
     */
    public function test_one_bad_policy_does_not_abort_a_good_ones_enforcement(): void
    {
        $badPackage = $this->edgeLikePackage();
        $goodPackage = Package::factory()->create();
        $computer = $this->computer();

        SoftwarePolicy::factory()->create([
            'package_id' => $badPackage->id, 'project_id' => $computer->project_id, 'mode' => PolicyMode::Enforce,
        ]);
        $goodPolicy = SoftwarePolicy::factory()->create([
            'package_id' => $goodPackage->id, 'project_id' => $computer->project_id, 'mode' => PolicyMode::Enforce,
        ]);

        $service = app(PolicyService::class);
        // enforceAll() runs every enforce-mode policy in one pass, same as
        // the scheduled command; the bad one must not stop the good one.
        $service->enforceAll();

        $this->assertTrue(
            DeploymentJob::where('package_id', $goodPackage->id)->exists(),
            'the good policy must still have queued its remediation'
        );
        $this->assertFalse(DeploymentJob::where('package_id', $badPackage->id)->exists());
    }

    public function test_per_computer_enforcement_also_skips_a_non_deployable_package(): void
    {
        $package = $this->edgeLikePackage();
        $computer = $this->computer();
        SoftwarePolicy::factory()->create([
            'package_id' => $package->id, 'project_id' => $computer->project_id, 'mode' => PolicyMode::Enforce,
        ]);

        $queued = app(PolicyService::class)->enforceForComputer($computer);

        $this->assertSame(0, $queued);
    }

    // ── PackageShow — the "Deploy this package" form ─────────────────────

    public function test_the_deploy_form_is_replaced_by_the_reason_for_a_non_deployable_package(): void
    {
        $package = $this->edgeLikePackage();

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->assertOk()
            ->assertSee('OS-managed')
            ->assertDontSee('wire:submit="deploy"', false);
    }

    /**
     * Defence in depth: even if the form were somehow submitted for a
     * package that cannot deploy, the page must flash the reason rather
     * than 500 — this method had NO try/catch around queue() before.
     */
    public function test_calling_deploy_directly_on_a_blocked_package_flashes_not_crashes(): void
    {
        $package = $this->edgeLikePackage();
        $computer = $this->computer();

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->set('deploy_computer_id', $computer->id)
            ->set('deploy_action', 'update')
            ->call('deploy')
            ->assertOk();

        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_the_deploy_form_still_works_for_an_ordinary_package(): void
    {
        $package = Package::factory()->create();
        $computer = $this->computer();

        Livewire::actingAs($this->admin())
            ->test(PackageShow::class, ['package' => $package])
            ->set('deploy_computer_id', $computer->id)
            ->set('deploy_action', 'install')
            ->call('deploy')
            ->assertOk();

        $this->assertSame(1, DeploymentJob::count());
    }

    // ── PackageForm — where an operator actually sets the mode ──────────

    public function test_the_edit_form_saves_a_new_management_mode(): void
    {
        $package = Package::factory()->create(['management_mode' => PackageMode::Deploy])->fresh();

        Livewire::actingAs($this->admin())
            ->test(PackageForm::class, ['package' => $package])
            ->set('management_mode', 'os_managed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(PackageMode::OsManaged, $package->fresh()->management_mode);
    }

    public function test_the_edit_form_prefills_the_stored_mode(): void
    {
        // ->fresh(): PackageForm is only ever reached via Livewire's
        // route-model binding, which does a real SELECT — a page load never
        // hands mount() the in-memory object create() returned. Matching
        // that here, rather than the exact same class of bug the model fix
        // just closed, but for winget_scopeless (an unrelated, pre-existing
        // column with the identical DB-default gap, harmless because it
        // only manifests on an object that was never actually re-fetched).
        $package = $this->edgeLikePackage()->fresh();

        Livewire::actingAs($this->admin())
            ->test(PackageForm::class, ['package' => $package])
            ->assertSet('management_mode', 'os_managed');
    }

    public function test_a_new_package_defaults_to_deploy_in_the_form(): void
    {
        $category = PackageCategory::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(PackageForm::class)
            ->assertSet('management_mode', 'deploy')
            ->set('package_category_id', $category->id)
            ->set('name', 'A New Package')
            ->set('winget_id', 'Vendor.NewPackage')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Package::where('name', 'A New Package')->first()->isDeployable());
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        $package = Package::factory()->create()->fresh();

        Livewire::actingAs($this->admin())
            ->test(PackageForm::class, ['package' => $package])
            ->set('management_mode', 'not-a-real-mode')
            ->call('save')
            ->assertHasErrors('management_mode');
    }
}
