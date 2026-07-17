<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Computers\ComputersIndex;
use App\Livewire\Dashboard;
use App\Livewire\Deployments\DeploymentsIndex;
use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\Computer;
use App\Models\DeploymentJob;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;
    private Client $globex;
    private Project $acmeProject;
    private Project $globexProject;
    private Computer $acmeComputer;
    private Computer $globexComputer;
    private User $acmeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->acme = Client::factory()->create(['company_name' => 'Acme Corp']);
        $this->globex = Client::factory()->create(['company_name' => 'Globex']);
        $this->acmeProject = Project::factory()->for($this->acme)->create(['name' => 'Acme Fleet']);
        $this->globexProject = Project::factory()->for($this->globex)->create(['name' => 'Globex Fleet']);
        $this->acmeComputer = Computer::factory()->for($this->acmeProject)->online()->create(['hostname' => 'ACME-PC']);
        $this->globexComputer = Computer::factory()->for($this->globexProject)->create(['hostname' => 'GLOBEX-PC']);

        $this->acmeUser = User::factory()->create(['client_id' => $this->acme->id]);
        $this->acmeUser->assignRole(RoleEnum::Client->value);
    }

    public function test_unbound_client_user_gets_a_friendly_dashboard_not_a_404(): void
    {
        $unbound = tap(User::factory()->create(['client_id' => null]),
            fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        $this->actingAs($unbound)->get('/dashboard')
            ->assertOk()
            ->assertSee("isn't linked to a client yet", false);
    }

    public function test_client_user_sees_only_their_computers(): void
    {
        Livewire::actingAs($this->acmeUser)
            ->test(ComputersIndex::class)
            ->assertSee('ACME-PC')
            ->assertDontSee('GLOBEX-PC');
    }

    public function test_client_user_sees_only_their_projects(): void
    {
        Livewire::actingAs($this->acmeUser)
            ->test(ProjectsIndex::class)
            ->assertSee('Acme Fleet')
            ->assertDontSee('Globex Fleet');
    }

    public function test_client_user_sees_only_their_deployments(): void
    {
        DeploymentJob::factory()->create(['computer_id' => $this->acmeComputer->id]);
        DeploymentJob::factory()->create(['computer_id' => $this->globexComputer->id]);

        Livewire::actingAs($this->acmeUser)
            ->test(DeploymentsIndex::class)
            ->assertSee('ACME-PC')
            ->assertDontSee('GLOBEX-PC');
    }

    public function test_unbound_client_role_user_fails_closed_and_sees_nothing(): void
    {
        // A Client-role account created without a client binding must not
        // fall back to staff-wide visibility.
        $unbound = tap(User::factory()->create(['client_id' => null]),
            fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        Livewire::actingAs($unbound)
            ->test(ComputersIndex::class)
            ->assertDontSee('ACME-PC')
            ->assertDontSee('GLOBEX-PC');

        $this->actingAs($unbound)
            ->get("/computers/{$this->acmeComputer->id}")
            ->assertForbidden();
    }

    public function test_unbound_viewer_is_staff_and_sees_all_clients(): void
    {
        // Viewer is an internal read-only role — no binding means fleet-wide
        // visibility on purpose. Binding one to a client scopes it.
        $viewer = tap(User::factory()->create(['client_id' => null]),
            fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        Livewire::actingAs($viewer)
            ->test(ComputersIndex::class)
            ->assertSee('ACME-PC')
            ->assertSee('GLOBEX-PC');
    }

    public function test_client_bound_viewer_is_scoped_like_a_client(): void
    {
        $boundViewer = tap(User::factory()->create(['client_id' => $this->acme->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        Livewire::actingAs($boundViewer)
            ->test(ComputersIndex::class)
            ->assertSee('ACME-PC')
            ->assertDontSee('GLOBEX-PC');

        $this->actingAs($boundViewer)
            ->get("/computers/{$this->globexComputer->id}")
            ->assertForbidden();
    }

    public function test_direct_access_to_foreign_computer_is_forbidden(): void
    {
        $this->actingAs($this->acmeUser)
            ->get("/computers/{$this->globexComputer->id}")
            ->assertForbidden();

        $this->actingAs($this->acmeUser)
            ->get("/computers/{$this->acmeComputer->id}")
            ->assertOk();
    }

    public function test_foreign_project_fails_policy_check(): void
    {
        $this->assertTrue($this->acmeUser->can('view', $this->acmeProject));
        $this->assertFalse($this->acmeUser->can('view', $this->globexProject));
    }

    public function test_client_dashboard_is_scoped_portal(): void
    {
        DeploymentJob::factory()->failed()->create(['computer_id' => $this->acmeComputer->id]);
        DeploymentJob::factory()->failed()->create(['computer_id' => $this->globexComputer->id]);

        Livewire::actingAs($this->acmeUser)
            ->test(Dashboard::class)
            ->assertSee('Your projects')
            ->assertSee('Acme Fleet')
            ->assertSee('Download agent')
            ->assertSee('ACME-PC')
            ->assertDontSee('Globex')
            ->assertViewHas('stats', fn ($stats) => $stats['online'] === 1 && $stats['failed'] === 1);
    }

    public function test_staff_dashboard_is_unchanged(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('Fleet by client');
    }

    public function test_agent_download_script_is_personalised_without_embedding_keys(): void
    {
        $response = $this->get("/download/agent/{$this->acmeProject->download_token}")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="install-piodeploy-agent.ps1"');

        $script = $response->getContent();
        $this->assertStringContainsString('Acme Fleet', $script);
        $this->assertStringContainsString('Mandatory = $true', $script);              // ApiKey is operator-supplied
        $this->assertDoesNotMatchRegularExpression('/pio_[A-Za-z0-9]{20,}/', $script); // never embeds a real key
        $this->assertStringContainsString('/download/agent/', $script);     // binary url present
    }

    public function test_agent_install_script_provisions_the_vc_runtime(): void
    {
        // With a bundle published, the installer body ensures the VC++ runtime,
        // so a fresh machine will not fail app installs with -1073741515 /
        // 0xC0000135. Publish a stand-in bundle so the install branch renders.
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')
            ->put(\App\Http\Controllers\AgentDownloadController::BUNDLE_PATH, 'zip-bytes');

        $script = $this->get("/download/agent/{$this->acmeProject->download_token}")
            ->assertOk()->getContent();

        $this->assertStringContainsString('vc_redist.x64.exe', $script);
        $this->assertStringContainsString('/quiet', $script);
    }

    public function test_agent_download_rejects_unknown_and_archived_tokens(): void
    {
        $this->get('/download/agent/definitely-not-a-token')->assertNotFound();

        $this->acmeProject->update(['status' => \App\Enums\ProjectStatus::Archived]);
        $this->get("/download/agent/{$this->acmeProject->download_token}")->assertNotFound();
    }

    public function test_agent_binary_404s_until_a_bundle_is_published(): void
    {
        // Isolate from any real published bundle on the local disk.
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->get("/download/agent/{$this->globexProject->download_token}/binary")->assertNotFound();
    }

    public function test_admin_can_bind_user_to_client(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setClient', $target->id, (string) $this->acme->id);

        $this->assertSame($this->acme->id, $target->fresh()->client_id);
        $this->assertDatabaseHas('activity_log', ['description' => 'client_assigned', 'subject_id' => $target->id]);

        // Unbind back to staff
        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setClient', $target->id, null);
        $this->assertNull($target->fresh()->client_id);
    }

    public function test_manager_cannot_bind_clients(): void
    {
        $manager = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Manager->value));
        $target = User::factory()->create();

        Livewire::actingAs($manager)
            ->test(\App\Livewire\Admin\ManageUsers::class)
            ->call('setClient', $target->id, (string) $this->acme->id)
            ->assertForbidden();
    }
}
