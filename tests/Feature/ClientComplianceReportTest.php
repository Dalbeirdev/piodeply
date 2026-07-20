<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ClientComplianceReportNotification;
use App\Services\ClientComplianceReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Client compliance reports: data strictly scoped per client, download
 * authorization for staff and own-client users only, and the opt-in
 * monthly mailer.
 */
class ClientComplianceReportTest extends TestCase
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

    /** A client with one project and two machines; plus a decoy client. */
    private function fixtures(): array
    {
        $client = Client::factory()->create(['company_name' => 'Acme Corp']);
        $project = Project::factory()->create(['client_id' => $client->id]);
        Computer::factory()->create(['project_id' => $project->id, 'hostname' => 'ACME-01', 'last_seen_at' => now()]);
        Computer::factory()->create(['project_id' => $project->id, 'hostname' => 'ACME-02', 'last_seen_at' => null]);

        $other = Client::factory()->create(['company_name' => 'Globex Ltd']);
        $otherProject = Project::factory()->create(['client_id' => $other->id]);
        Computer::factory()->create(['project_id' => $otherProject->id, 'hostname' => 'GLOBEX-01']);

        return [$client, $other];
    }

    public function test_report_data_is_scoped_to_the_client(): void
    {
        [$client] = $this->fixtures();

        $data = app(ClientComplianceReportService::class)->dataFor($client);

        $this->assertSame(2, $data['fleet']['total']);
        $this->assertSame(1, $data['fleet']['online']);
        $this->assertTrue($data['computers']->pluck('hostname')->contains('ACME-01'));
        $this->assertFalse($data['computers']->pluck('hostname')->contains('GLOBEX-01'));
    }

    public function test_report_includes_the_clients_software_policies(): void
    {
        // Guards the policy query itself: a wrong column name slipped past
        // sqlite once (it reads an unknown double-quoted identifier as a
        // string literal and silently matches nothing) while MySQL threw a
        // 500 in production. Asserting a real policy shows up means the
        // query must actually resolve columns, on every driver.
        [$client] = $this->fixtures();
        $project = $client->projects()->firstOrFail();
        $package = \App\Models\Package::factory()->create(['name' => 'Report Widget']);
        \App\Models\SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => $package->id, 'action' => 'install',
        ]);
        \App\Models\SoftwarePolicy::factory()->create([
            'project_id' => $project->id, 'package_id' => \App\Models\Package::factory()->create()->id,
            'action' => 'update', 'mode' => \App\Enums\PolicyMode::Disabled,
        ]);

        $data = app(ClientComplianceReportService::class)->dataFor($client);

        $this->assertCount(1, $data['software']); // disabled policy excluded
        $this->assertSame('Report Widget', $data['software']->first()['policy']->package->name);
    }

    public function test_staff_can_download_the_pdf(): void
    {
        [$client] = $this->fixtures();

        $response = $this->actingAs($this->admin())
            ->get(route('clients.compliance-report', $client))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('acme-corp-compliance', $response->headers->get('Content-Disposition'));
    }

    public function test_a_client_user_can_download_their_own_report_but_not_anothers(): void
    {
        [$client, $other] = $this->fixtures();

        $tenant = tap(User::factory()->create(['client_id' => $client->id]), fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        $this->actingAs($tenant)->get(route('clients.compliance-report', $client))->assertOk();
        $this->actingAs($tenant)->get(route('clients.compliance-report', $other))->assertForbidden();
    }

    public function test_monthly_command_emails_only_opted_in_clients_portal_users(): void
    {
        Notification::fake();
        [$client, $other] = $this->fixtures();

        $client->update(['monthly_report' => true]); // opted in
        $recipient = tap(User::factory()->create(['client_id' => $client->id]), fn (User $u) => $u->assignRole(RoleEnum::Client->value));
        // $other stays opted out, and has a portal user who must get nothing.
        $decoy = tap(User::factory()->create(['client_id' => $other->id]), fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        $this->artisan('reports:client-compliance')->assertSuccessful();

        Notification::assertSentTo($recipient, ClientComplianceReportNotification::class);
        Notification::assertNotSentTo($decoy, ClientComplianceReportNotification::class);
    }

    public function test_clients_index_can_toggle_the_monthly_report(): void
    {
        [$client] = $this->fixtures();

        \Livewire\Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Clients\ClientsIndex::class)
            ->call('toggleMonthlyReport', $client->id);

        $this->assertTrue($client->fresh()->monthly_report);
    }
}
