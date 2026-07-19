<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use App\Notifications\DunningReminderNotification;
use App\Services\WebhookService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Dunning completion: the portal-wide unpaid banner, the paced reminder
 * command, and the recovery reset when a payment finally lands.
 */
class DunningTest extends TestCase
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

    /* ─────────────────────── In-app banner ───────────────────────────── */

    public function test_staff_see_the_past_due_banner_everywhere(): void
    {
        Account::current()->forceFill(['status' => 'past_due'])->save();

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Payment failed — your subscription is past due.')
            ->assertSee('Update payment method');
    }

    public function test_suspended_reads_differently_and_active_shows_nothing(): void
    {
        Account::current()->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->admin())->get('/dashboard')
            ->assertSee('Subscription suspended');

        Account::current()->forceFill(['status' => 'active'])->save();
        $this->actingAs($this->admin())->get('/dashboard')
            ->assertDontSee('Subscription suspended')
            ->assertDontSee('past due');
    }

    public function test_client_portal_users_never_see_the_billing_banner(): void
    {
        Account::current()->forceFill(['status' => 'past_due'])->save();

        $client = Client::factory()->create();
        $tenant = tap(User::factory()->create(['client_id' => $client->id]), fn (User $u) => $u->assignRole(RoleEnum::Client->value));

        $this->actingAs($tenant)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('past due');
    }

    /* ─────────────────────── Reminder command ────────────────────────── */

    public function test_reminders_send_once_then_pace_at_three_days(): void
    {
        Notification::fake();
        $this->admin(); // the billing contact
        Account::current()->forceFill(['status' => 'past_due'])->save();

        $this->artisan('billing:dunning-reminders')->assertSuccessful();
        Notification::assertSentTimes(DunningReminderNotification::class, 1);
        $this->assertNotNull(Account::current()->fresh()->dunning_notified_at);

        // The next day: nothing (paced).
        $this->artisan('billing:dunning-reminders')->assertSuccessful();
        Notification::assertSentTimes(DunningReminderNotification::class, 1);

        // Three days on: reminded again.
        Account::current()->forceFill(['dunning_notified_at' => now()->subDays(4)])->save();
        $this->artisan('billing:dunning-reminders')->assertSuccessful();
        Notification::assertSentTimes(DunningReminderNotification::class, 2);
    }

    public function test_healthy_accounts_are_never_dunned(): void
    {
        Notification::fake();
        $this->admin();
        Account::current()->forceFill(['status' => 'active'])->save();

        $this->artisan('billing:dunning-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /* ─────────────────────── Recovery ────────────────────────────────── */

    public function test_a_successful_payment_resets_the_dunning_cadence(): void
    {
        Notification::fake();
        $this->admin();
        Account::current()->forceFill([
            'stripe_id' => 'cus_1', 'status' => 'past_due', 'dunning_notified_at' => now(),
        ])->save();

        app(WebhookService::class)->handle([
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_ok', 'customer' => 'cus_1', 'amount_paid' => 4800, 'currency' => 'usd']],
        ]);

        $this->assertNull(Account::current()->fresh()->dunning_notified_at);
    }
}
