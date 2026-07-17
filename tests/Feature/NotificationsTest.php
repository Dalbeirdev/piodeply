<?php

namespace Tests\Feature;

use App\Enums\JobAction;
use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\NotificationChannels;
use App\Models\Computer;
use App\Models\ComputerSoftware;
use App\Models\DeploymentJob;
use App\Models\NotificationChannel;
use App\Models\Package;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\ComputerService;
use App\Services\DeploymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    private function fakeHttpOk(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function failJob(): DeploymentJob
    {
        $job = DeploymentJob::factory()->create([
            'computer_id' => Computer::factory()->create(['hostname' => 'FAIL-PC'])->id,
            'package_id'  => Package::factory()->create(['name' => 'FailApp'])->id,
            'action'      => JobAction::Install,
            'status'      => \App\Enums\JobStatus::Running,
            'attempts'    => 3, 'max_attempts' => 3, // exhausted → terminal failure
        ]);

        return app(DeploymentService::class)->reportResult($job, false, 1603, 'boom', 'Fatal error during installation');
    }

    // ── Event triggers ─────────────────────────────────────────────────

    public function test_failed_job_notifies_email_and_webhook_subscribers(): void
    {
        $this->fakeHttpOk();
        NotificationChannel::factory()->create(['destination' => 'ops@techpio.test', 'events' => ['job.failed']]);
        NotificationChannel::factory()->webhook()->events(['job.failed'])->create();
        NotificationChannel::factory()->events(['computer.registered'])->create(); // not subscribed
        NotificationChannel::factory()->events(['job.failed'])->create(['is_active' => false]); // disabled

        $this->failJob();

        Mail::assertSent(\App\Mail\ChannelNotification::class, 1);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $payload['event'] === 'job.failed'
                && str_contains($payload['title'], 'FailApp')
                && str_contains($payload['title'], 'FAIL-PC')
                && $payload['data']['failure_reason'] === 'Fatal error during installation'
                && str_contains($payload['text'], 'FailApp');
        });
    }

    /**
     * Teams renders a MessageCard, not Slack markdown. The payload must carry
     * the card shape, a facts table, and never the *bold* that shows as
     * literal asterisks there.
     */
    public function test_the_webhook_payload_renders_as_a_teams_card(): void
    {
        $this->fakeHttpOk();
        NotificationChannel::factory()->webhook()->events(['job.failed'])->create();

        $this->failJob();

        Http::assertSent(function ($request) {
            $p = $request->data();

            $facts = collect($p['sections'][0]['facts'] ?? []);

            // Teams Workflows adaptive card.
            $card = $p['attachments'][0]['content'] ?? [];
            $adaptiveText = $card['body'][0]['text'] ?? '';
            $adaptiveFacts = collect($card['body'][1]['facts'] ?? []);

            return $p['type'] === 'message'
                && $p['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
                && ($card['type'] ?? null) === 'AdaptiveCard'
                && str_starts_with($adaptiveText, '⚠️')
                && ($card['body'][0]['color'] ?? null) === 'Attention'
                && $adaptiveFacts->contains(fn ($f) => $f['title'] === 'Package' && str_contains($f['value'], 'FailApp'))
                // Legacy connector still served.
                && $p['@type'] === 'MessageCard'
                && $p['themeColor'] === 'D64545'
                // Slack / Discord, no markdown.
                && ! str_contains($p['text'], '*')
                && $p['content'] === $p['text'];
        });
    }

    public function test_an_enquiry_alert_is_coloured_and_labelled_for_the_event(): void
    {
        $this->fakeHttpOk();
        NotificationChannel::factory()->webhook()->events(['lead.received'])->create();

        app(\App\Services\NotificationService::class)->notify('lead.received', 'Access request from Jane', [
            'company' => 'Acme IT',
        ]);

        Http::assertSent(fn ($request) => $request->data()['themeColor'] === '0F766E'
            && str_starts_with($request->data()['title'], '✉️'));
    }

    public function test_retryable_failure_does_not_notify(): void
    {
        NotificationChannel::factory()->events(['job.failed'])->create();

        $job = DeploymentJob::factory()->create([
            'computer_id' => Computer::factory()->create()->id,
            'package_id'  => Package::factory()->create()->id,
            'action'      => JobAction::Install,
            'status'      => \App\Enums\JobStatus::Running,
            'attempts'    => 1, 'max_attempts' => 3, // retries remain → back to pending
        ]);
        app(DeploymentService::class)->reportResult($job, false, 1, null, 'transient');

        Mail::assertNothingSent();
    }

    public function test_new_computer_registration_notifies(): void
    {
        NotificationChannel::factory()->events(['computer.registered'])->create();
        $project = Project::factory()->create();

        app(ComputerService::class)->register($project, (string) Str::uuid(), [
            'hostname' => 'BRAND-NEW-PC',
        ]);

        Mail::assertSent(\App\Mail\ChannelNotification::class, 1);

        // Re-registration of the same agent is not "new" — no second alert.
        Mail::fake();
        app(ComputerService::class)->register(
            $project,
            Computer::first()->agent_uuid,
            ['hostname' => 'BRAND-NEW-PC']
        );
        Mail::assertNothingSent();
    }

    public function test_offline_check_alerts_once_per_outage(): void
    {
        NotificationChannel::factory()->events(['agent.offline'])->create();

        $computer = Computer::factory()->create(['last_seen_at' => now()->subHours(2)]);
        Computer::factory()->create(['last_seen_at' => now()->subMinutes(5)]);  // online
        Computer::factory()->neverSeen()->create();                              // never enrolled → skip

        $this->artisan('agents:check-offline')->expectsOutputToContain('1 alert(s)');
        Mail::assertSent(\App\Mail\ChannelNotification::class, 1);

        // Second run: already notified → silent.
        $this->artisan('agents:check-offline')->expectsOutputToContain('0 alert(s)');

        // Heartbeat re-arms the alert for the next outage.
        app(ComputerService::class)->heartbeat($computer);
        $this->assertNull($computer->fresh()->offline_notified_at);
    }

    public function test_drift_digest_reports_policies_needing_attention(): void
    {
        $this->fakeHttpOk();
        NotificationChannel::factory()->webhook()->events(['policy.drift'])->create();

        $project = Project::factory()->create();
        $package = Package::factory()->create(['name' => 'DriftApp']);
        Computer::factory()->create(['project_id' => $project->id]); // missing the package → drift
        SoftwarePolicy::factory()->audit()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);

        // A fully compliant policy stays out of the digest.
        $cleanProject = Project::factory()->create();
        $cleanPackage = Package::factory()->create();
        $cleanPc = Computer::factory()->create(['project_id' => $cleanProject->id]);
        ComputerSoftware::factory()->create([
            'computer_id' => $cleanPc->id, 'name' => $cleanPackage->winget_id, 'source' => 'winget',
        ]);
        SoftwarePolicy::factory()->create([
            'project_id' => $cleanProject->id, 'package_id' => $cleanPackage->id, 'action' => 'install',
        ]);

        $this->artisan('policies:drift-digest')->expectsOutputToContain('1 policy(ies)');

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $payload['event'] === 'policy.drift'
                && str_contains(json_encode($payload['data']), 'DriftApp');
        });
    }

    public function test_dead_webhook_is_recorded_and_does_not_break_the_caller(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $channel = NotificationChannel::factory()->webhook()->events(['job.failed'])->create();

        $job = $this->failJob(); // must not throw

        $this->assertSame('failed', $job->status->value);
        $this->assertStringContainsString('HTTP 500', $channel->fresh()->last_error);
    }

    // ── Admin UI ───────────────────────────────────────────────────────

    public function test_admin_can_create_a_channel_and_send_a_test(): void
    {
        $this->fakeHttpOk();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(NotificationChannels::class)
            ->call('create')
            ->set('name', 'Ops alerts')
            ->set('type', 'webhook')
            ->set('destination', 'https://hooks.example.test/xyz')
            ->set('events', ['job.failed', 'agent.offline'])
            ->call('save')
            ->assertHasNoErrors();

        $channel = NotificationChannel::firstOrFail();
        $this->assertSame(['job.failed', 'agent.offline'], $channel->events);

        Livewire::actingAs($admin)
            ->test(NotificationChannels::class)
            ->call('sendTest', $channel->id);

        Http::assertSent(fn ($request) => $request->data()['event'] === 'test');
        $this->assertNotNull($channel->fresh()->last_sent_at);
    }

    /**
     * A Teams / Azure Logic Apps webhook URL runs to hundreds of characters.
     * The column was VARCHAR(255) while validation allowed 500, so such a URL
     * passed the form and then hit a raw "Data too long" 500 at the database.
     */
    public function test_a_long_webhook_url_can_be_saved(): void
    {
        $this->fakeHttpOk();

        // ~430 chars, in the shape of a real Logic Apps / Teams webhook.
        $longUrl = 'https://prod-12.westeurope.logic.azure.com:443/workflows/'
            .str_repeat('a1b2c3d4', 20)
            .'/triggers/manual/paths/invoke?api-version=2016-06-01&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig='
            .str_repeat('X', 60);

        $this->assertGreaterThan(255, strlen($longUrl));

        Livewire::actingAs($this->admin())
            ->test(NotificationChannels::class)
            ->call('create')
            ->set('name', 'Teams alerts')
            ->set('type', 'webhook')
            ->set('destination', $longUrl)
            ->set('events', ['lead.received'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($longUrl, NotificationChannel::firstOrFail()->destination);
    }

    public function test_validation_rejects_bad_destinations(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(NotificationChannels::class)
            ->call('create')
            ->set('name', 'Bad email')
            ->set('type', 'email')
            ->set('destination', 'not-an-email')
            ->set('events', ['job.failed'])
            ->call('save')
            ->assertHasErrors('destination');

        Livewire::actingAs($admin)
            ->test(NotificationChannels::class)
            ->call('create')
            ->set('name', 'No events')
            ->set('type', 'email')
            ->set('destination', 'ok@techpio.test')
            ->set('events', [])
            ->call('save')
            ->assertHasErrors('events');
    }

    public function test_only_settings_managers_can_open_the_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/notifications')->assertOk();

        foreach ([RoleEnum::Manager, RoleEnum::Viewer] as $role) {
            $user = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role->value));
            $this->actingAs($user)->get('/admin/notifications')->assertForbidden();
        }
    }
}
