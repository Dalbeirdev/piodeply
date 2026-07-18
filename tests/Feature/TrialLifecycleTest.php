<?php

namespace Tests\Feature;

use App\Livewire\Billing\Subscription;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TrialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    public function test_reminder_emails_a_trial_ending_within_three_days_once(): void
    {
        Notification::fake();
        $this->admin();
        $plan = Plan::factory()->create();
        $account = Account::factory()->create([
            'status'        => 'trialing',
            'plan_id'       => $plan->id,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $this->artisan('billing:trial-reminders')->assertSuccessful();

        Notification::assertSentTimes(TrialEndingNotification::class, 1);
        $this->assertNotNull($account->fresh()->trial_reminder_sent_at);

        // A second run must not re-send.
        $this->artisan('billing:trial-reminders')->assertSuccessful();
        Notification::assertSentTimes(TrialEndingNotification::class, 1);
    }

    public function test_reminder_ignores_trials_further_out(): void
    {
        Notification::fake();
        $this->admin();
        Account::factory()->create([
            'status'        => 'trialing',
            'trial_ends_at' => now()->addDays(10),
        ]);

        $this->artisan('billing:trial-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_reminder_ignores_already_expired_trials(): void
    {
        Notification::fake();
        $this->admin();
        Account::factory()->create([
            'status'        => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->artisan('billing:trial-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_billing_page_is_gated_and_renders_for_an_admin(): void
    {
        Plan::factory()->recommended()->create();

        // A viewer without settings.manage cannot reach it.
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/billing/subscription')->assertForbidden();

        // An admin can. Stripe is unconfigured in tests, so a fresh account
        // (status "none", not subscribed) shows the configuration notice.
        Livewire::actingAs($this->admin())
            ->test(Subscription::class)
            ->assertOk()
            ->assertSee('configured yet');
    }

    public function test_sync_stripe_fails_cleanly_without_keys(): void
    {
        config(['cashier.secret' => null]);

        $this->artisan('billing:sync-stripe')
            ->expectsOutputToContain('No Stripe secret key configured')
            ->assertFailed();
    }
}
