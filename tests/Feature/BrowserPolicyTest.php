<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\BrowserPolicies\BrowserPoliciesIndex;
use App\Livewire\BrowserPolicies\BrowserPolicyForm;
use App\Livewire\BrowserPolicies\BrowserPolicyShow;
use App\Models\BrowserPolicy;
use App\Models\Client;
use App\Models\Computer;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\User;
use App\Services\BrowserPolicyService;
use App\Services\ProjectService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class BrowserPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function service(): BrowserPolicyService
    {
        return app(BrowserPolicyService::class);
    }

    private function userWithRole(RoleEnum $role, ?int $clientId = null): User
    {
        return tap(
            User::factory()->create(['client_id' => $clientId]),
            fn (User $u) => $u->assignRole($role->value)
        );
    }

    /** @return array{project: Project, key: string} */
    private function projectWithKey(): array
    {
        $result = app(ProjectService::class)->create(new \App\DTOs\ProjectData(
            clientId: Client::factory()->create()->id,
            name: 'Agent Fleet ' . Str::random(4),
            description: null,
            status: \App\Enums\ProjectStatus::Active,
        ));

        return ['project' => $result['project'], 'key' => $result['plain_api_key']];
    }

    // ── Type registry ──────────────────────────────────────────────────

    public function test_disable_incognito_produces_the_documented_registry_values(): void
    {
        $policy = BrowserPolicy::factory()->make(['type' => 'disable_incognito', 'action' => 'disable', 'browsers' => ['all']]);

        $ops = $policy->operations();

        $this->assertSame(
            ['kind' => 'registry', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome', 'name' => 'IncognitoModeAvailability', 'value' => 1],
            $ops['chrome']
        );
        $this->assertSame('InPrivateModeAvailability', $ops['edge']['name']);
        $this->assertSame('SOFTWARE\\Policies\\BraveSoftware\\Brave', $ops['brave']['path']);
        $this->assertSame(['kind' => 'firefox_json', 'key' => 'DisablePrivateBrowsing', 'value' => true], $ops['firefox']);
        $this->assertSame(['kind' => 'unsupported'], $ops['opera']);
    }

    public function test_enable_action_writes_the_permissive_value(): void
    {
        $policy = BrowserPolicy::factory()->make(['action' => 'enable', 'browsers' => ['chrome']]);

        $this->assertSame(0, $policy->operations()['chrome']['value']);
        $this->assertArrayNotHasKey('firefox', $policy->operations());
    }

    // ── Agent document ─────────────────────────────────────────────────

    public function test_agent_document_contains_active_policies_only(): void
    {
        $project = Project::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);

        $active = BrowserPolicy::factory()->create(['project_id' => $project->id]);
        BrowserPolicy::factory()->inactive()->create(['project_id' => $project->id, 'type' => 'disable_guest_mode']);
        BrowserPolicy::factory()->create(); // other project

        $document = $this->service()->documentFor($computer);

        $this->assertCount(1, $document['policies']);
        $this->assertSame($active->id, $document['policies'][0]['policy_id']);
        $this->assertSame('disable_incognito', $document['policies'][0]['type']);
        $this->assertArrayHasKey('chrome', $document['policies'][0]['operations']);
    }

    public function test_excluded_computers_get_an_empty_document(): void
    {
        $project = Project::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $policy = BrowserPolicy::factory()->create(['project_id' => $project->id]);
        $policy->excludedComputers()->attach($computer->id);

        $this->assertCount(0, $this->service()->documentFor($computer)['policies']);
    }

    public function test_agent_endpoint_serves_the_document(): void
    {
        ['project' => $project, 'key' => $key] = $this->projectWithKey();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        BrowserPolicy::factory()->create(['project_id' => $project->id]);

        $this->postJson('/api/v1/agent/browser-policies', [
            'agent_uuid' => $computer->agent_uuid,
        ], ['X-Api-Key' => $key])
            ->assertOk()
            ->assertJsonCount(1, 'policies')
            ->assertJsonPath('policies.0.operations.chrome.name', 'IncognitoModeAvailability');
    }

    // ── Results ingestion ──────────────────────────────────────────────

    public function test_agent_results_are_stored_and_upserted(): void
    {
        ['project' => $project, 'key' => $key] = $this->projectWithKey();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $policy = BrowserPolicy::factory()->create(['project_id' => $project->id]);

        $payload = fn (string $status) => [
            'agent_uuid' => $computer->agent_uuid,
            'results' => [[
                'policy_id' => $policy->id, 'browser' => 'chrome', 'status' => $status,
                'old_value' => null, 'new_value' => '1',
            ]],
        ];

        $this->postJson('/api/v1/agent/browser-policies/results', $payload('pending_restart'), ['X-Api-Key' => $key])
            ->assertOk()->assertJsonPath('stored', 1);
        $this->postJson('/api/v1/agent/browser-policies/results', $payload('compliant'), ['X-Api-Key' => $key])
            ->assertOk();

        $this->assertSame(1, $policy->results()->count()); // upsert, not accumulate
        $this->assertSame('compliant', $policy->results()->first()->status);
    }

    public function test_failure_transition_notifies_once(): void
    {
        Mail::fake();
        NotificationChannel::factory()->events(['browser_policy.failed'])->create();

        $project = Project::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $policy = BrowserPolicy::factory()->create(['project_id' => $project->id]);

        $report = fn (string $status) => $this->service()->ingestResults($computer, [[
            'policy_id' => $policy->id, 'browser' => 'chrome', 'status' => $status,
        ]]);

        $report('error');
        Mail::assertSent(\App\Mail\ChannelNotification::class, 1);

        $report('error'); // still failing — no second alert
        Mail::assertSent(\App\Mail\ChannelNotification::class, 1);

        $report('compliant');
        $report('non_compliant'); // new incident — alerts again
        Mail::assertSent(\App\Mail\ChannelNotification::class, 2);
    }

    // ── Compliance ─────────────────────────────────────────────────────

    public function test_compliance_summary_buckets_machines(): void
    {
        $project = Project::factory()->create();
        $policy = BrowserPolicy::factory()->create(['project_id' => $project->id, 'browsers' => ['chrome', 'firefox']]);

        $protected = Computer::factory()->create(['project_id' => $project->id]);
        $drifted = Computer::factory()->create(['project_id' => $project->id]);
        $pending = Computer::factory()->create(['project_id' => $project->id]);
        Computer::factory()->create(['project_id' => $project->id]); // awaiting agent
        $excluded = Computer::factory()->create(['project_id' => $project->id]);
        $policy->excludedComputers()->attach($excluded->id);

        $report = fn (Computer $computer, string $browser, string $status) => $this->service()->ingestResults($computer, [[
            'policy_id' => $policy->id, 'browser' => $browser, 'status' => $status,
        ]]);

        $report($protected, 'chrome', 'compliant');
        $report($protected, 'firefox', 'not_installed'); // neutral
        $report($drifted, 'chrome', 'non_compliant');
        $report($pending, 'chrome', 'pending_restart');

        $summary = $this->service()->complianceSummary($policy);

        $this->assertSame(4, $summary['target']);
        $this->assertSame(1, $summary['protected']);
        $this->assertSame(1, $summary['non_compliant']);
        $this->assertSame(2, $summary['pending']); // pending_restart + awaiting agent
        $this->assertSame(1, $summary['excluded']);
        $this->assertSame(25.0, $summary['percent']);
    }

    // ── UI ─────────────────────────────────────────────────────────────

    public function test_manager_can_create_a_browser_policy(): void
    {
        $project = Project::factory()->create();

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(BrowserPolicyForm::class)
            ->set('name', 'Block incognito')
            ->set('project_id', $project->id)
            ->set('type', 'disable_incognito')
            ->set('browsers', ['all'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('browser-policies.index'));

        $this->assertDatabaseHas('browser_policies', [
            'name' => 'Block incognito', 'type' => 'disable_incognito', 'status' => 'active',
        ]);
    }

    public function test_duplicate_type_per_project_is_a_conflict(): void
    {
        $existing = BrowserPolicy::factory()->create();

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(BrowserPolicyForm::class)
            ->set('name', 'Duplicate')
            ->set('project_id', $existing->project_id)
            ->set('type', $existing->type->value)
            ->set('browsers', ['all'])
            ->call('save')
            ->assertHasErrors('type');
    }

    public function test_show_page_renders_compliance_and_exclusion_toggle(): void
    {
        $policy = BrowserPolicy::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $policy->project_id, 'hostname' => 'BP-PC']);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(BrowserPolicyShow::class, ['policy' => $policy])
            ->assertSee('BP-PC')
            ->assertSee('Awaiting agent')
            ->call('toggleExclusion', $computer->id);

        $this->assertTrue($policy->excludedComputers()->whereKey($computer->id)->exists());
    }

    public function test_viewer_sees_but_cannot_manage(): void
    {
        $policy = BrowserPolicy::factory()->create();
        $viewer = $this->userWithRole(RoleEnum::Viewer);

        $this->actingAs($viewer)->get('/browser-policies')->assertOk();
        $this->actingAs($viewer)->get('/browser-policies/create')->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(BrowserPoliciesIndex::class)
            ->call('delete', $policy->id)
            ->assertForbidden();
    }

    public function test_computer_page_shows_browser_policy_status(): void
    {
        $project = Project::factory()->create();
        $computer = Computer::factory()->create(['project_id' => $project->id]);
        $policy = BrowserPolicy::factory()->create(['project_id' => $project->id, 'name' => 'Lockdown browsing']);
        $this->service()->ingestResults($computer, [[
            'policy_id' => $policy->id, 'browser' => 'chrome', 'status' => 'compliant',
        ]]);

        Livewire::actingAs($this->userWithRole(RoleEnum::Manager))
            ->test(\App\Livewire\Computers\ComputerShow::class, ['computer' => $computer])
            ->assertSee('Browser policies')
            ->assertSee('Lockdown browsing')
            ->assertSee('compliant');
    }

    // ── Integration API ────────────────────────────────────────────────

    public function test_api_crud_round_trip(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read', 'deploy']);

        $created = $this->postJson('/api/v1/browser-policies', [
            'name' => 'API policy', 'project_id' => $project->id,
            'type' => 'disable_incognito', 'browsers' => ['all'],
            'action' => 'disable', 'status' => 'active',
        ])->assertCreated()->json('data');

        $this->getJson('/api/v1/browser-policies')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/browser-policies/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.compliance.target', 0);

        $this->putJson("/api/v1/browser-policies/{$created['id']}", [
            'name' => 'API policy', 'project_id' => $project->id,
            'type' => 'disable_incognito', 'browsers' => ['chrome'],
            'action' => 'disable', 'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/browser-policies/{$created['id']}")->assertOk();
        $this->assertSame(0, BrowserPolicy::count());
    }

    public function test_api_writes_need_deploy_ability_and_manage_permission(): void
    {
        $project = Project::factory()->create();

        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager), ['read']);
        $this->postJson('/api/v1/browser-policies', [
            'name' => 'x', 'project_id' => $project->id, 'type' => 'disable_incognito',
            'browsers' => ['all'], 'action' => 'disable', 'status' => 'active',
        ])->assertForbidden();

        Sanctum::actingAs($this->userWithRole(RoleEnum::Viewer), ['deploy']);
        $this->postJson('/api/v1/browser-policies', [
            'name' => 'x', 'project_id' => $project->id, 'type' => 'disable_incognito',
            'browsers' => ['all'], 'action' => 'disable', 'status' => 'active',
        ])->assertForbidden();
    }

    public function test_tenancy_scopes_lists_and_conceals_foreign_policies(): void
    {
        $acme = Client::factory()->create();
        $foreign = BrowserPolicy::factory()->create(); // other client
        BrowserPolicy::factory()->create([
            'project_id' => Project::factory()->for($acme)->create()->id,
            'type' => 'disable_guest_mode',
        ]);

        Sanctum::actingAs($this->userWithRole(RoleEnum::Manager, $acme->id), ['read']);

        $this->getJson('/api/v1/browser-policies')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/browser-policies/{$foreign->id}")->assertNotFound();
    }
}
