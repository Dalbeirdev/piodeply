<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\DeployToComputer;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The deploy form should state the outcome before the click, not queue
 * first and report "nothing to do" afterwards.
 */
class SmartDeployButtonTest extends TestCase
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

    private function form(Computer $computer)
    {
        return Livewire::actingAs($this->admin())
            ->test(DeployToComputer::class, ['computer' => $computer]);
    }

    private function chrome(): Package
    {
        return Package::factory()->create(['name' => 'Google Chrome', 'winget_id' => 'Google.Chrome']);
    }

    private function installed(Computer $computer, ?string $version): void
    {
        ComputerSoftware::factory()->create([
            'computer_id' => $computer->id,
            'name'        => 'Google.Chrome',
            'version'     => $version,
            'source'      => 'winget',
        ]);
    }

    public function test_a_package_that_is_absent_offers_a_plain_install(): void
    {
        $computer = Computer::factory()->create(['hostname' => 'DALBEIR']);

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->assertViewHas('canQueue', true)
            ->assertViewHas('label', 'Install')
            ->assertSee('Not installed on DALBEIR');
    }

    public function test_a_package_that_is_current_reads_up_to_date_and_cannot_be_queued(): void
    {
        $computer = Computer::factory()->create(['hostname' => 'SUSHMITA-L11']);
        $this->installed($computer, '141.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->assertViewHas('canQueue', false)
            ->assertViewHas('label', 'Up to date')
            ->assertSee('Installed on SUSHMITA-L11')
            ->assertSee('141.0');
    }

    public function test_an_older_install_with_a_pinned_target_offers_the_upgrade(): void
    {
        $computer = Computer::factory()->create();
        $this->installed($computer, '138.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('target_version', '141.0')
            ->assertViewHas('canQueue', true)
            ->assertViewHas('label', 'Upgrade 138.0 → 141.0');
    }

    public function test_ticking_force_re_enables_a_satisfied_request(): void
    {
        $computer = Computer::factory()->create();
        $this->installed($computer, '141.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->assertViewHas('canQueue', false)
            ->set('force', true)
            ->assertViewHas('canQueue', true)
            ->assertViewHas('label', 'Reinstall');
    }

    public function test_uninstalling_something_absent_reads_not_installed(): void
    {
        $computer = Computer::factory()->create();

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('action', 'uninstall')
            ->assertViewHas('canQueue', false)
            ->assertViewHas('label', 'Not installed');
    }

    public function test_an_update_names_the_version_it_starts_from(): void
    {
        $computer = Computer::factory()->create();
        $this->installed($computer, '138.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('action', 'update')
            ->assertViewHas('canQueue', true)
            ->assertViewHas('label', 'Update from 138.0');
    }

    public function test_a_binary_package_admits_it_cannot_report_a_version(): void
    {
        $computer = Computer::factory()->create();
        $package = Package::factory()->create([
            'name' => 'Legacy Tool', 'installer_type' => 'msi', 'winget_id' => null, 'choco_id' => null,
        ]);

        $this->form($computer)
            ->set('package_id', $package->id)
            ->assertViewHas('versionKnown', false)
            ->assertViewHas('label', 'Install');
    }

    public function test_the_pinned_version_reaches_the_queued_job(): void
    {
        $computer = Computer::factory()->create();
        $this->installed($computer, '138.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('target_version', '141.0')
            ->call('queue');

        $job = DeploymentJob::sole();
        $this->assertSame('141.0', $job->target_version);
        $this->assertSame('138.0', $job->installed_version_before);
    }

    public function test_a_blank_pinned_version_means_latest_not_an_empty_string(): void
    {
        $computer = Computer::factory()->create();

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('target_version', '   ')
            ->call('queue');

        $this->assertNull(DeploymentJob::sole()->target_version);
    }

    /**
     * A rollback with no target is not a job anything can carry out: the agent
     * builds no command, fails, and retries twice more for nothing.
     */
    public function test_a_rollback_without_a_version_cannot_be_queued(): void
    {
        $computer = Computer::factory()->create();
        $this->installed($computer, '150.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('action', 'rollback')
            ->assertViewHas('canQueue', false)
            ->assertViewHas('label', 'Pin a version to roll back to');
    }

    public function test_a_rollback_with_a_version_is_allowed(): void
    {
        $computer = Computer::factory()->create();
        $this->installed($computer, '150.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('action', 'rollback')
            ->set('target_version', '141.0')
            ->assertViewHas('canQueue', true)
            ->call('queue');

        $this->assertSame('141.0', DeploymentJob::sole()->target_version);
    }

    public function test_submitting_a_versionless_rollback_is_refused_not_queued(): void
    {
        $computer = Computer::factory()->create();
        $this->installed($computer, '150.0');

        $this->form($computer)
            ->set('package_id', $this->chrome()->id)
            ->set('action', 'rollback')
            ->call('queue')
            ->assertHasErrors('target_version');

        $this->assertSame(0, DeploymentJob::count());
    }

    /** Belt and braces: the service refuses it even if a caller gets past the form. */
    public function test_the_service_itself_refuses_a_versionless_rollback(): void
    {
        $result = app(\App\Services\DeploymentService::class)->queueIfNeeded(
            Computer::factory()->create(),
            $this->chrome(),
            \App\Enums\JobAction::Rollback,
        );

        $this->assertSame(\App\Enums\QueueOutcome::Invalid, $result->outcome);
        $this->assertStringContainsString('needs the version to roll back to', $result->message);
        $this->assertSame(0, DeploymentJob::count());
    }

    public function test_no_selection_shows_a_neutral_button(): void
    {
        $this->form(Computer::factory()->create())
            ->assertViewHas('label', 'Queue')
            ->assertViewHas('canQueue', true);
    }
}
