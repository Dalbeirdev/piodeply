<?php

namespace Tests\Feature;

use App\DTOs\ProjectData;
use App\Enums\Role as RoleEnum;
use App\Livewire\Projects\ProjectEnrollment;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\EnrollmentScriptService;
use App\Services\ProjectService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->project = app(ProjectService::class)->create(new ProjectData(
            clientId: Client::factory()->create(['company_name' => 'Acme Ltd'])->id,
            name: 'Acme Fleet',
        ))['project'];
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    private function page()
    {
        return Livewire::actingAs($this->admin())
            ->test(ProjectEnrollment::class, ['project' => $this->project]);
    }

    public function test_the_page_offers_every_rollout_method(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Group Policy (Active Directory)')
            ->assertSee('Intune / Entra')
            ->assertSee('RMM / one-liner')
            ->assertSee('Single machine');
    }

    public function test_the_gpo_script_carries_the_projects_download_url(): void
    {
        $this->page()->assertSee(route('agent.download', $this->project->download_token));
    }

    public function test_a_pasted_key_lands_in_the_script(): void
    {
        $this->page()
            ->set('apiKey', 'pio_realkey123')
            ->assertSee("-ApiKey \$apiKey", false)
            ->assertSee("pio_realkey123");
    }

    public function test_without_a_key_the_script_carries_a_placeholder_and_says_so(): void
    {
        $this->page()
            ->assertSee(EnrollmentScriptService::KEY_PLACEHOLDER)
            ->assertSee('No key entered');
    }

    public function test_a_key_cannot_break_out_of_the_powershell_literal(): void
    {
        // A bare quote would close the string and let the rest run as code.
        $scripts = app(EnrollmentScriptService::class)
            ->all($this->project, "pio_x'; Remove-Item C:\\ -Recurse; '");

        // Every quote is doubled, so the whole thing stays one inert literal
        // rather than a string followed by a command.
        $this->assertStringContainsString(
            "\$apiKey      = 'pio_x''; Remove-Item C:\\ -Recurse; '''",
            $scripts['gpo']['body']
        );

        // And a newline cannot end the statement either.
        $withNewline = app(EnrollmentScriptService::class)
            ->all($this->project, "pio_a\nRemove-Item C:\\")['gpo']['body'];

        $this->assertStringContainsString("\$apiKey      = 'pio_aRemove-Item C:\\'", $withNewline);
    }

    public function test_a_project_name_cannot_close_the_comment_banner(): void
    {
        $this->project->update(['name' => 'Acme #> ; Remove-Item C:\ -Recurse ; <#']);

        $body = app(EnrollmentScriptService::class)->all($this->project->fresh(), 'pio_k')['gpo']['body'];

        // The banner stays a banner.
        $this->assertStringNotContainsString('#> ; Remove-Item', $body);
        $this->assertStringContainsString('# > ; Remove-Item', $body);
    }

    public function test_an_apostrophe_in_a_client_name_does_not_corrupt_the_script(): void
    {
        // Blade's {{ }} would render this as &#039; — plain-text scripts must
        // not be HTML-escaped.
        $this->project->client->update(['company_name' => "O'Brien & Sons"]);

        $body = app(EnrollmentScriptService::class)->all($this->project->fresh(), 'pio_k')['gpo']['body'];

        $this->assertStringContainsString("O'Brien & Sons", $body);
        $this->assertStringNotContainsString('&#039;', $body);
        $this->assertStringNotContainsString('&amp;', $body);
    }

    public function test_the_gpo_script_is_idempotent_and_version_aware(): void
    {
        $body = app(EnrollmentScriptService::class)->all($this->project, 'pio_k')['gpo']['body'];

        // Exits when already current, rather than reinstalling every boot.
        $this->assertStringContainsString('Get-Service -Name $serviceName', $body);
        $this->assertStringContainsString('-ge [version]$minVersion', $body);
        // and upgrades a fleet left on an older build.
        $this->assertStringContainsString(EnrollmentScriptService::CURRENT_AGENT_VERSION, $body);
    }

    public function test_switching_method_changes_the_script_shown(): void
    {
        $this->page()
            ->assertSee('Group Policy computer startup script')
            ->call('select', 'intune')
            ->assertSee('Intune / Entra platform script')
            ->assertDontSee('Group Policy computer startup script');
    }

    public function test_an_unknown_method_falls_back_rather_than_failing(): void
    {
        $this->page()->set('method', 'nonsense')->assertOk()->assertViewHas('selected', 'gpo');
    }

    public function test_the_page_is_tenant_scoped(): void
    {
        $otherClient = Client::factory()->create();
        $outsider = tap(
            User::factory()->create(['client_id' => $otherClient->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Client->value)
        );

        Livewire::actingAs($outsider)
            ->test(ProjectEnrollment::class, ['project' => $this->project])
            ->assertForbidden();
    }

    public function test_the_route_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('projects.enrollment', $this->project))
            ->assertOk()
            ->assertSee('Enrol machines');
    }
}
