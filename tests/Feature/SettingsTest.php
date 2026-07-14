<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\SettingsPage;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\PolicyService;
use App\Services\SettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    // ── Page & persistence ─────────────────────────────────────────────

    public function test_admin_can_save_settings_and_they_apply(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SettingsPage::class)
            ->set('company_name', 'TechPio MSP')
            ->set('online_threshold_seconds', 120)
            ->set('offline_after_minutes', 30)
            ->set('default_max_attempts', 5)
            ->set('failure_backoff_hours', 12)
            ->set('activity_retention_days', 90)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('TechPio MSP', $this->settings()->get('branding.company_name'));
        $this->assertSame(120, $this->settings()->get('agent.online_threshold_seconds'));
        $this->assertDatabaseHas('activity_log', ['description' => 'settings_saved']);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(SettingsPage::class)
            ->set('online_threshold_seconds', 5) // below the 60s floor
            ->call('save')
            ->assertHasErrors('online_threshold_seconds');
    }

    public function test_only_settings_managers_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings')->assertOk();

        $manager = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Manager->value));
        $this->actingAs($manager)->get('/admin/settings')->assertForbidden();
    }

    // ── Settings actually drive behaviour ──────────────────────────────

    public function test_online_threshold_setting_changes_online_detection(): void
    {
        $computer = Computer::factory()->create(['last_seen_at' => now()->subSeconds(120)]);

        $this->assertTrue($computer->isOnline()); // default 300s

        $this->settings()->set('agent.online_threshold_seconds', 60);
        $this->assertFalse($computer->fresh()->isOnline());
    }

    public function test_default_max_attempts_setting_applies_to_new_jobs(): void
    {
        $this->settings()->set('deployments.default_max_attempts', 5);

        $job = app(DeploymentService::class)->queue(
            Computer::factory()->create(),
            Package::factory()->create(),
            JobAction::Install
        );

        $this->assertSame(5, $job->max_attempts);
    }

    public function test_failure_backoff_setting_controls_policy_requeue(): void
    {
        $project = Project::factory()->create();
        $package = Package::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $policy = SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
        DeploymentJob::factory()->create([
            'computer_id' => $computer->id, 'package_id' => $package->id,
            'action' => JobAction::Install, 'status' => JobStatus::Failed,
            'finished_at' => now()->subHours(2),
        ]);

        // Default backoff (23h): a 2-hour-old failure blocks the requeue.
        $this->assertSame(0, app(PolicyService::class)->enforce($policy));

        // Shorten the backoff to 1 hour: the same failure no longer blocks.
        $this->settings()->set('policies.failure_backoff_hours', 1);
        $this->assertSame(1, app(PolicyService::class)->enforce($policy));
    }

    public function test_offline_alert_minutes_setting_drives_the_check_command(): void
    {
        \App\Models\NotificationChannel::factory()->events(['agent.offline'])->create();
        \Illuminate\Support\Facades\Mail::fake();

        Computer::factory()->create(['last_seen_at' => now()->subMinutes(30)]);

        // Default 60 minutes: 30 minutes of silence is not an outage yet.
        $this->artisan('agents:check-offline')->expectsOutputToContain('0 alert(s)');

        $this->settings()->set('notifications.offline_after_minutes', 15);
        $this->artisan('agents:check-offline')->expectsOutputToContain('1 alert(s)');
    }

    public function test_prune_command_respects_retention_setting(): void
    {
        $old = activity('test')->log('ancient');
        $old->forceFill(['created_at' => now()->subDays(200)])->save();
        activity('test')->log('recent');

        $this->settings()->set('retention.activity_days', 180);
        $this->artisan('logs:prune')->expectsOutputToContain('Pruned 1');

        $this->assertDatabaseMissing('activity_log', ['description' => 'ancient']);
        $this->assertDatabaseHas('activity_log', ['description' => 'recent']);
    }

    public function test_sidebar_shows_the_configured_company_name(): void
    {
        $this->settings()->set('branding.company_name', 'Acme Managed IT');

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertSee('Acme Managed IT');
    }
}
