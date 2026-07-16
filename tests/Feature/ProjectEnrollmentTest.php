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
        // Anything that is not key-shaped never reaches the script at all.
        $attacks = [
            "pio_x'; Remove-Item C:\\ -Recurse; '",   // ASCII quote
            "pio_a\nRemove-Item C:\\",                 // newline ends a statement
            "pio_x\u{2019}; Write-Output PWNED; \u{2018}x", // U+2019/U+2018 close a PS literal
            "pio_x\u{201A}; Write-Output PWNED; \u{201B}x", // U+201A/U+201B likewise
            "pio_x`; Write-Output PWNED",              // backtick
            "pio_x\x00; Write-Output PWNED",           // null byte
        ];

        foreach ($attacks as $attack) {
            $body = app(EnrollmentScriptService::class)->all($this->project, $attack)['gpo']['body'];

            $this->assertStringContainsString(EnrollmentScriptService::KEY_PLACEHOLDER, $body, 'rejected key should fall back to the placeholder');
            $this->assertStringNotContainsString('PWNED', $body);
            $this->assertStringNotContainsString('Remove-Item C:\\', $body);
        }
    }

    /**
     * PowerShell ends a single-quoted string on four Unicode quotes as well as
     * the ASCII one, so escaping by doubling ' was not enough.
     */
    public function test_a_unicode_quote_cannot_close_the_powershell_literal(): void
    {
        foreach (["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "'"] as $quote) {
            $this->assertFalse(
                EnrollmentScriptService::looksLikeAKey("pio_abc{$quote}rest"),
                "a key containing {$quote} must be rejected"
            );
        }
    }

    public function test_a_real_key_is_accepted(): void
    {
        $this->assertTrue(EnrollmentScriptService::looksLikeAKey('pio_gTwGgtN0ZqZjS2abcdef1234567890'));
        $this->assertTrue(EnrollmentScriptService::looksLikeAKey('pio_with-dashes_and_underscores'));

        $this->assertFalse(EnrollmentScriptService::looksLikeAKey('short'));
        $this->assertFalse(EnrollmentScriptService::looksLikeAKey(str_repeat('a', 129)));
        $this->assertFalse(EnrollmentScriptService::looksLikeAKey('has spaces in it'));
    }

    public function test_the_page_says_a_key_was_rejected_rather_than_quietly_ignoring_it(): void
    {
        $this->page()
            ->set('apiKey', "pio_x\u{2019}; Write-Output PWNED; \u{2018}x")
            ->assertViewHas('keyRejected', true)
            ->assertSee('does not look like a project key')
            ->assertDontSee('PWNED');
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
