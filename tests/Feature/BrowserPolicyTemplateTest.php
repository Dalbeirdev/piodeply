<?php

namespace Tests\Feature;

use App\Enums\BrowserPolicyType;
use App\Enums\Role as RoleEnum;
use App\Livewire\BrowserPolicies\BrowserPolicyTemplates;
use App\Models\BrowserPolicy;
use App\Models\BrowserPolicyTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\BrowserPolicyTemplateService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Policy templates: built-in bundles apply as individual policies, custom
 * templates round-trip from a project, and access is gated.
 */
class BrowserPolicyTemplateTest extends TestCase
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

    public function test_builtin_templates_reference_only_catalogue_types(): void
    {
        $builtins = BrowserPolicyTemplateService::builtins();

        $this->assertCount(7, $builtins);
        $this->assertArrayHasKey('high-security', $builtins);
        $this->assertArrayHasKey('kiosk', $builtins);

        foreach ($builtins as $template) {
            $this->assertNotEmpty($template['types']);
            foreach ($template['types'] as $type) {
                $this->assertInstanceOf(BrowserPolicyType::class, $type);
            }
            // A bundle must not list the same policy twice.
            $values = array_map(fn ($t) => $t->value, $template['types']);
            $this->assertSame($values, array_unique($values), "{$template['name']} lists a duplicate type");
        }
    }

    public function test_applying_a_template_creates_one_policy_per_type(): void
    {
        $project = Project::factory()->create();
        $service = app(BrowserPolicyTemplateService::class);
        $template = $service->find('developer');

        $result = $service->apply($template, $project);

        $this->assertSame(count($template['types']), $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(count($template['types']), BrowserPolicy::where('project_id', $project->id)->count());
        $this->assertDatabaseHas('browser_policies', [
            'project_id' => $project->id, 'type' => 'disable_guest_mode', 'status' => 'active',
        ]);
    }

    public function test_applying_skips_types_the_project_already_has(): void
    {
        $project = Project::factory()->create();
        BrowserPolicy::factory()->create(['project_id' => $project->id, 'type' => 'disable_guest_mode']);

        $service = app(BrowserPolicyTemplateService::class);
        $result = $service->apply($service->find('developer'), $project);

        $this->assertSame(1, $result['skipped']);
        // Still exactly one guest-mode policy — the existing one was kept.
        $this->assertSame(1, BrowserPolicy::where('project_id', $project->id)->where('type', 'disable_guest_mode')->count());
    }

    public function test_a_project_can_be_captured_as_a_custom_template_and_reapplied(): void
    {
        $source = Project::factory()->create();
        BrowserPolicy::factory()->create(['project_id' => $source->id, 'type' => 'disable_incognito']);
        BrowserPolicy::factory()->create(['project_id' => $source->id, 'type' => 'disable_downloads']);

        $service = app(BrowserPolicyTemplateService::class);
        $saved = $service->captureFromProject('Acme baseline', 'Our standard', $source);

        $this->assertDatabaseHas('browser_policy_templates', ['name' => 'Acme baseline']);

        // The custom template shows up in all() and applies elsewhere.
        $target = Project::factory()->create();
        $result = $service->apply($service->find('custom-'.$saved->id), $target);

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas('browser_policies', ['project_id' => $target->id, 'type' => 'disable_downloads']);
    }

    public function test_component_applies_a_template_to_a_project(): void
    {
        $project = Project::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(BrowserPolicyTemplates::class)
            ->assertSee('High Security')
            ->assertSee('Kiosk')
            ->call('startApply', 'developer')
            ->set('applyProjectId', $project->id)
            ->call('apply');

        $this->assertTrue(BrowserPolicy::where('project_id', $project->id)->exists());
    }

    public function test_component_deletes_a_custom_template_only(): void
    {
        $custom = BrowserPolicyTemplate::factory()->create(['name' => 'Old baseline']);

        Livewire::actingAs($this->admin())
            ->test(BrowserPolicyTemplates::class)
            ->call('deleteTemplate', $custom->id);

        $this->assertDatabaseMissing('browser_policy_templates', ['id' => $custom->id]);
    }

    public function test_capture_requires_the_project_to_have_policies(): void
    {
        $empty = Project::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(BrowserPolicyTemplates::class)
            ->set('captureProjectId', $empty->id)
            ->set('captureName', 'Nothing here')
            ->call('capture')
            ->assertHasErrors('captureProjectId');

        $this->assertDatabaseCount('browser_policy_templates', 0);
    }

    public function test_templates_page_requires_manage_permission(): void
    {
        $viewer = User::factory()->create(); // no roles

        Livewire::actingAs($viewer)
            ->test(BrowserPolicyTemplates::class)
            ->assertForbidden();
    }

    /* ─────────────────────── Import / export ─────────────────────────── */

    public function test_project_export_round_trips_through_import_with_settings_intact(): void
    {
        $source = Project::factory()->create(['name' => 'Acme HQ']);
        BrowserPolicy::factory()->create(['project_id' => $source->id, 'type' => 'disable_incognito']);
        BrowserPolicy::factory()->create([
            'project_id' => $source->id, 'type' => 'force_homepage',
            'settings' => ['url' => 'https://intranet.acme.com'], 'browsers' => ['chrome', 'edge'],
        ]);

        $document = $this->actingAs($this->admin())
            ->get(route('browser-policies.export.project', $source))
            ->assertOk()
            ->assertHeader('Content-Disposition')
            ->json();

        $this->assertSame('piodeploy.browser-policies', $document['format']);
        $this->assertCount(2, $document['policies']);

        // Import it back as a template, apply to a fresh project: the value
        // policy must arrive with its URL and browser scope intact.
        $service = app(BrowserPolicyTemplateService::class);
        $result = $service->import($document, 'Acme baseline');
        $this->assertSame(2, $result['imported']);

        $target = Project::factory()->create();
        $service->apply($service->find('custom-'.$result['template']->id), $target);

        $applied = BrowserPolicy::where('project_id', $target->id)->where('type', 'force_homepage')->firstOrFail();
        $this->assertSame(['url' => 'https://intranet.acme.com'], $applied->settings);
        $this->assertSame(['chrome', 'edge'], $applied->browsers);
    }

    public function test_builtin_templates_can_be_exported_by_key(): void
    {
        $this->actingAs($this->admin())
            ->get(route('browser-policies.export.template', ['key' => 'high-security']))
            ->assertOk()
            ->assertJsonPath('format', 'piodeploy.browser-policies')
            ->assertJsonPath('name', 'High Security');

        $this->actingAs($this->admin())
            ->get(route('browser-policies.export.template', ['key' => 'no-such-template']))
            ->assertNotFound();
    }

    public function test_import_skips_unknown_types_and_rejects_foreign_documents(): void
    {
        $service = app(BrowserPolicyTemplateService::class);

        $result = $service->import([
            'format' => 'piodeploy.browser-policies',
            'version' => 1,
            'policies' => [
                ['type' => 'disable_incognito', 'action' => 'disable'],
                ['type' => 'policy_from_the_future', 'action' => 'disable'],
            ],
        ], 'Mixed import');

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);

        $this->expectException(\InvalidArgumentException::class);
        $service->import(['something' => 'else'], 'Garbage');
    }

    public function test_export_requires_manage_permission(): void
    {
        $project = Project::factory()->create();
        BrowserPolicy::factory()->create(['project_id' => $project->id]);

        $viewer = User::factory()->create(); // no roles

        $this->actingAs($viewer)
            ->get(route('browser-policies.export.project', $project))
            ->assertForbidden();
    }
}
