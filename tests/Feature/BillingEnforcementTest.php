<?php

namespace Tests\Feature;

use App\DTOs\ProjectData;
use App\Exceptions\DeviceLimitReachedException;
use App\Livewire\Billing\Subscription;
use App\Models\Account;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PaymentReceiptNotification;
use App\Services\ComputerService;
use App\Services\ProjectService;
use App\Services\WebhookService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BillingEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function service(): ComputerService
    {
        return app(ComputerService::class);
    }

    private function limitAccountTo(int $limit): Account
    {
        return tap(Account::current(), fn (Account $a) => $a->update([
            'device_limit' => $limit, 'device_limit_overridden' => true,
        ]));
    }

    public function test_no_plan_means_unlimited_enrollment(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->count(3)->create(['project_id' => $project->id]);

        // Account has no plan / no limit → a new device is allowed.
        $computer = $this->service()->register($project, (string) Str::uuid(), ['hostname' => 'NEW'], '1.0');
        $this->assertNotNull($computer->id);
    }

    public function test_a_new_device_past_the_limit_is_blocked(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]); // 1 device
        $this->limitAccountTo(1);

        $this->expectException(DeviceLimitReachedException::class);
        $this->service()->register($project, (string) Str::uuid(), ['hostname' => 'OVER'], '1.0');
    }

    public function test_existing_device_can_always_re_register(): void
    {
        $project = Project::factory()->create();
        $uuid = (string) Str::uuid();
        Computer::factory()->create(['project_id' => $project->id, 'agent_uuid' => $uuid]); // 1 device
        $this->limitAccountTo(1); // at the ceiling

        // Same agent re-registering is not a new device → allowed.
        $computer = $this->service()->register($project, $uuid, ['hostname' => 'RENAMED'], '1.1');
        $this->assertSame('RENAMED', $computer->hostname);
    }

    public function test_admin_override_grants_capacity(): void
    {
        $project = Project::factory()->create();
        Computer::factory()->create(['project_id' => $project->id]);
        $this->limitAccountTo(1); // blocked at 1

        // Raise the override; the next device now fits.
        Account::current()->update(['device_limit' => 5]);
        $computer = $this->service()->register($project, (string) Str::uuid(), ['hostname' => 'FITS'], '1.0');
        $this->assertNotNull($computer->id);
    }

    public function test_the_register_endpoint_returns_402_when_full(): void
    {
        $client = Client::factory()->create();
        $result = app(ProjectService::class)->create(new ProjectData(clientId: $client->id, name: 'Fleet'));
        $project = $result['project'];

        Computer::factory()->create(['project_id' => $project->id]);
        $this->limitAccountTo(1);

        $this->postJson('/api/v1/agent/register', [
            'agent_uuid' => (string) Str::uuid(),
            'inventory'  => ['hostname' => 'BLOCKED'],
        ], ['X-Api-Key' => $result['plain_api_key'], 'Accept' => 'application/json'])
            ->assertStatus(402)
            ->assertJsonPath('error', 'device_limit_reached');
    }

    public function test_admin_can_set_and_clear_the_device_limit_override(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));

        Livewire::actingAs($admin)->test(Subscription::class)
            ->set('overrideLimit', 500)
            ->call('saveDeviceLimit')
            ->assertHasNoErrors();

        $account = Account::current();
        $this->assertTrue($account->device_limit_overridden);
        $this->assertSame(500, $account->device_limit);

        Livewire::actingAs($admin)->test(Subscription::class)
            ->set('overrideLimit', null)
            ->call('saveDeviceLimit');

        $this->assertFalse(Account::current()->device_limit_overridden);
    }

    public function test_a_successful_renewal_emails_a_receipt(): void
    {
        Notification::fake();
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
        $plan = Plan::factory()->create();
        Account::current()->update(['stripe_id' => 'cus_1', 'plan_id' => $plan->id]);

        app(WebhookService::class)->handle([
            'type' => 'invoice.paid',
            'data' => ['object' => ['customer' => 'cus_1', 'amount_paid' => 4800, 'currency' => 'usd']],
        ]);

        Notification::assertSentTo($admin, PaymentReceiptNotification::class);
    }
}
