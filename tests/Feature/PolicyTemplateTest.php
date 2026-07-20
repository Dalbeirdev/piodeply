<?php

namespace Tests\Feature;

use App\Enums\PolicyAction;
use App\Enums\Role as RoleEnum;
use App\Livewire\Policies\PolicyTemplates;
use App\Models\Client;
use App\Models\Package;
use App\Models\PolicyTemplate;
use App\Models\Project;
use App\Models\SoftwarePolicy;
use App\Models\User;
use App\Services\PolicyTemplateService;
use Database\Seeders\PolicyTemplateSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PolicyTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PolicyTemplateSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    public function test_builtin_kits_are_seeded_idempotently(): void
    {
        $this->assertSame(3, PolicyTemplate::where('is_builtin', true)->count());
        $before = \App\Models\PolicyTemplateItem::count();

        $this->seed(PolicyTemplateSeeder::class); // again

        $this->assertSame(3, PolicyTemplate::where('is_builtin', true)->count());
        $this->assertSame($before, \App\Models\PolicyTemplateItem::count(), 're-seeding never duplicates items');
    }

    public function test_applying_a_template_creates_policies_and_missing_packages(): void
    {
        $project = Project::factory()->create();
        $template = PolicyTemplate::where('name', 'Standard workstation')->first();

        $result = app(PolicyTemplateService::class)->applyToProject($template, $project, null);

        $this->assertSame(10, $result['created'], '5 apps × install+update');
        $this->assertSame(0, $result['skipped']);
        // The catalogue was empty — packages were created from winget ids.
        $this->assertNotNull(Package::where('winget_id', 'Google.Chrome')->first());
        $this->assertSame(10, SoftwarePolicy::where('project_id', $project->id)->count());
    }

    public function test_reapplying_only_fills_gaps(): void
    {
        $project = Project::factory()->create();
        $template = PolicyTemplate::where('name', 'Standard workstation')->first();
        $service = app(PolicyTemplateService::class);

        $service->applyToProject($template, $project, null);
        $again = $service->applyToProject($template, $project, null);

        $this->assertSame(0, $again['created']);
        $this->assertSame(10, $again['skipped']);
        $this->assertSame(10, SoftwarePolicy::where('project_id', $project->id)->count(), 'no duplicates, ever');
    }

    public function test_a_projects_policies_snapshot_into_a_template(): void
    {
        $project = Project::factory()->create();
        $winget = Package::factory()->create(['winget_id' => 'Mozilla.Firefox', 'name' => 'Firefox']);
        $binary = Package::factory()->create(['winget_id' => null, 'name' => 'In-house EXE']);
        SoftwarePolicy::create(['project_id' => $project->id, 'package_id' => $winget->id, 'action' => PolicyAction::Install, 'mode' => 'enforce', 'version_mode' => 'latest', 'frequency' => 'weekly']);
        SoftwarePolicy::create(['project_id' => $project->id, 'package_id' => $binary->id, 'action' => PolicyAction::Install, 'mode' => 'enforce', 'version_mode' => 'latest', 'frequency' => 'weekly']);

        $result = app(PolicyTemplateService::class)->createFromProject($project, 'Acme kit', null, null);

        $this->assertSame(1, $result['captured'], 'only the winget policy travels');
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('Mozilla.Firefox', $result['template']->items->sole()->winget_id);
    }

    public function test_a_tenant_can_apply_only_to_their_own_projects(): void
    {
        $mine = Client::factory()->create();
        $myProject = Project::factory()->create(['client_id' => $mine->id]);
        $otherProject = Project::factory()->create(); // someone else's
        $owner = tap(User::factory()->create(['client_id' => $mine->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));
        $template = PolicyTemplate::where('name', 'Standard workstation')->first();

        // Own project: works.
        Livewire::actingAs($owner)
            ->test(PolicyTemplates::class)
            ->set("applyProject.{$template->id}", $myProject->id)
            ->call('apply', $template->id);
        $this->assertSame(10, SoftwarePolicy::where('project_id', $myProject->id)->count());

        // Someone else's project id smuggled in: not found, nothing created.
        try {
            Livewire::actingAs($owner)
                ->test(PolicyTemplates::class)
                ->set("applyProject.{$template->id}", $otherProject->id)
                ->call('apply', $template->id);
            $this->fail('applying to another tenant\'s project must fail');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        $this->assertSame(0, SoftwarePolicy::where('project_id', $otherProject->id)->count());
    }

    public function test_tenants_cannot_author_or_delete_templates(): void
    {
        $owner = tap(User::factory()->create(['client_id' => Client::factory()->create()->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));
        $template = PolicyTemplate::where('is_builtin', false)->first()
            ?? PolicyTemplate::first();

        Livewire::actingAs($owner)
            ->test(PolicyTemplates::class)
            ->call('delete', $template->id)
            ->assertForbidden();

        Livewire::actingAs($owner)
            ->test(PolicyTemplates::class)
            ->set('newName', 'Sneaky global template')
            ->set('sourceProjectId', Project::factory()->create()->id)
            ->call('saveAsTemplate')
            ->assertForbidden();

        $this->assertNull(PolicyTemplate::where('name', 'Sneaky global template')->first());
    }

    public function test_staff_can_save_and_delete_custom_templates(): void
    {
        $project = Project::factory()->create();
        $pkg = Package::factory()->create(['winget_id' => 'Google.Chrome', 'name' => 'Chrome']);
        SoftwarePolicy::create(['project_id' => $project->id, 'package_id' => $pkg->id, 'action' => PolicyAction::Update, 'mode' => 'enforce', 'version_mode' => 'latest', 'frequency' => 'daily']);

        Livewire::actingAs($this->admin())
            ->test(PolicyTemplates::class)
            ->set('newName', 'My kit')
            ->set('sourceProjectId', $project->id)
            ->call('saveAsTemplate');

        $template = PolicyTemplate::where('name', 'My kit')->first();
        $this->assertNotNull($template);
        $this->assertFalse($template->is_builtin);

        Livewire::actingAs($this->admin())
            ->test(PolicyTemplates::class)
            ->call('delete', $template->id);

        $this->assertNull(PolicyTemplate::find($template->id));
        $this->assertSame(1, SoftwarePolicy::count(), 'deleting a template never touches applied policies');
    }
}
