<?php

namespace Tests\Feature;

use App\DTOs\ProjectData;
use App\Enums\Role as RoleEnum;
use App\Livewire\Projects\ProjectForm;
use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
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

    public function test_project_pages_are_permission_gated(): void
    {
        $project = Project::factory()->create();

        $this->get('/projects')->assertRedirect('/login');

        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));
        $this->actingAs($viewer)->get('/projects')->assertOk();
        $this->actingAs($viewer)->get('/projects/create')->assertForbidden();

        $this->actingAs($this->admin())->get("/projects/{$project->id}/edit")->assertOk();
    }

    public function test_client_has_many_projects(): void
    {
        $client = Client::factory()->create();
        Project::factory()->count(3)->for($client)->create();

        $this->assertCount(3, $client->projects);
    }

    public function test_creating_a_project_issues_api_key_and_download_token(): void
    {
        $client = Client::factory()->create();

        $result = app(ProjectService::class)->create(new ProjectData(
            clientId: $client->id,
            name: 'Workstation Rollout',
        ));

        $project = $result['project'];
        $plainKey = $result['plain_api_key'];

        $this->assertStringStartsWith('pio_', $plainKey);
        $this->assertSame(44, strlen($plainKey));
        $this->assertSame(hash('sha256', $plainKey), $project->api_key_hash);
        $this->assertSame(substr($plainKey, 0, 12), $project->api_key_prefix);
        $this->assertNotEmpty($project->download_token);
        $this->assertStringContainsString("/download/agent/{$project->download_token}", $project->downloadUrl());

        // Plaintext must never be persisted anywhere in the row.
        $raw = (array) \DB::table('projects')->where('id', $project->id)->first();
        $this->assertFalse(in_array($plainKey, $raw, true));
    }

    public function test_find_by_api_key_resolves_only_the_right_key(): void
    {
        $client = Client::factory()->create();
        $service = app(ProjectService::class);
        $a = $service->create(new ProjectData(clientId: $client->id, name: 'Project A'));
        $b = $service->create(new ProjectData(clientId: $client->id, name: 'Project B'));

        $this->assertTrue(Project::findByApiKey($a['plain_api_key'])->is($a['project']));
        $this->assertTrue(Project::findByApiKey($b['plain_api_key'])->is($b['project']));
        $this->assertNull(Project::findByApiKey('pio_' . str_repeat('x', 40)));
        $this->assertNull(Project::findByApiKey('not-even-prefixed'));
    }

    public function test_rotating_the_key_kills_the_old_one_and_logs_activity(): void
    {
        $client = Client::factory()->create();
        $service = app(ProjectService::class);
        $created = $service->create(new ProjectData(clientId: $client->id, name: 'Rotate Me'));
        $project = $created['project'];
        $oldKey = $created['plain_api_key'];

        $newKey = $service->rotateApiKey($project);

        $this->assertNotSame($oldKey, $newKey);
        $this->assertNull(Project::findByApiKey($oldKey));
        $this->assertTrue(Project::findByApiKey($newKey)->is($project));
        $this->assertNotNull($project->fresh()->api_key_rotated_at);
        $this->assertDatabaseHas('activity_log', [
            'log_name'    => 'projects',
            'description' => 'api_key_rotated',
            'subject_id'  => $project->id,
        ]);
    }

    public function test_form_creates_project_and_flashes_key_once(): void
    {
        $client = Client::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('client_id', $client->id)
            ->set('name', 'Front Desk Fleet')
            ->set('description', 'Reception machines')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('new_api_key');

        $this->assertDatabaseHas('projects', ['name' => 'Front Desk Fleet', 'client_id' => $client->id]);
    }

    public function test_project_name_unique_per_client_but_reusable_across_clients(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        Project::factory()->for($clientA)->create(['name' => 'Standard Build']);

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('client_id', $clientA->id)
            ->set('name', 'Standard Build')
            ->call('save')
            ->assertHasErrors(['name']);

        Livewire::actingAs($this->admin())
            ->test(ProjectForm::class)
            ->set('client_id', $clientB->id)
            ->set('name', 'Standard Build')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_rotate_requires_permission(): void
    {
        $project = Project::factory()->create();
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        Livewire::actingAs($viewer)
            ->test(ProjectsIndex::class)
            ->call('rotateKey', $project->id)
            ->assertForbidden();
    }

    public function test_rotation_reveals_key_in_component_state(): void
    {
        $project = Project::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(ProjectsIndex::class)
            ->call('rotateKey', $project->id);

        $revealed = $component->get('revealedKey');
        $this->assertStringStartsWith('pio_', $revealed);
        $this->assertTrue(Project::findByApiKey($revealed)->is($project));

        $component->call('dismissKey');
        $this->assertNull($component->get('revealedKey'));
    }

    public function test_soft_delete_and_restore(): void
    {
        $project = Project::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ProjectsIndex::class)
            ->call('delete', $project->id);

        $this->assertSoftDeleted('projects', ['id' => $project->id]);

        Livewire::actingAs($this->admin())
            ->test(ProjectsIndex::class)
            ->set('showTrashed', true)
            ->call('restore', $project->id);

        $this->assertNull($project->fresh()->deleted_at);
    }

    public function test_search_and_client_filter(): void
    {
        $acme = Client::factory()->create(['company_name' => 'Acme Corp']);
        $globex = Client::factory()->create(['company_name' => 'Globex']);
        Project::factory()->for($acme)->create(['name' => 'Acme Workstations']);
        Project::factory()->for($globex)->create(['name' => 'Globex Servers']);

        Livewire::actingAs($this->admin())
            ->test(ProjectsIndex::class)
            ->set('search', 'Acme')
            ->assertSee('Acme Workstations')
            ->assertDontSee('Globex Servers')
            ->set('search', '')
            ->set('clientId', $globex->id)
            ->assertSee('Globex Servers')
            ->assertDontSee('Acme Workstations');
    }

    public function test_menu_shows_projects_for_permitted_users(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertSee('Projects');
    }
}
