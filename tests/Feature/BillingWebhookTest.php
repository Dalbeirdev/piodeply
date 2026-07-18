<?php

namespace Tests\Feature;

use App\Livewire\Admin\WebhookEvents as WebhookEventsPage;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\PaymentFailedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class BillingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_testsecret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.stripe.webhook_secret' => $this->secret]);
    }

    /** POST an event with a valid Stripe-Signature header computed from the secret. */
    private function sendEvent(array $event): TestResponse
    {
        $payload = json_encode($event);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", $this->secret);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}",
            'CONTENT_TYPE'          => 'application/json',
        ], $payload);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    private function accountWithSubscription(string $stripeStatus = 'trialing'): Account
    {
        $account = Account::factory()->create(['stripe_id' => 'cus_1']);
        $account->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => $stripeStatus,
            'stripe_price' => 'price_1', 'quantity' => 1,
        ]);

        return $account;
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['id' => 'evt_x', 'type' => 'invoice.paid']))
            ->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_a_valid_event_is_processed_and_recorded(): void
    {
        $this->accountWithSubscription();

        $this->sendEvent([
            'id' => 'evt_1', 'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_1', 'status' => 'active', 'cancel_at_period_end' => false,
                'items' => ['data' => [['price' => ['id' => 'price_1']]]]]],
        ])->assertOk();

        $this->assertDatabaseHas('webhook_events', ['stripe_id' => 'evt_1', 'status' => 'processed']);
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => 'sub_1', 'stripe_status' => 'active']);
        $this->assertSame('active', Account::first()->status);
    }

    public function test_redelivered_events_are_idempotent(): void
    {
        $this->accountWithSubscription();
        $event = [
            'id' => 'evt_dup', 'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_1', 'status' => 'active', 'cancel_at_period_end' => false]],
        ];

        $this->sendEvent($event)->assertOk();
        $this->sendEvent($event)->assertOk()->assertSee('Already processed');

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_cancel_at_period_end_moves_the_account_to_grace(): void
    {
        $this->accountWithSubscription('active');

        $this->sendEvent([
            'id' => 'evt_2', 'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_1', 'status' => 'active',
                'cancel_at_period_end' => true, 'current_period_end' => now()->addDays(20)->timestamp]],
        ])->assertOk();

        $this->assertSame('grace', Account::first()->status);
    }

    public function test_subscription_deleted_marks_the_account_canceled(): void
    {
        $this->accountWithSubscription('active');

        $this->sendEvent([
            'id' => 'evt_3', 'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_1', 'status' => 'canceled', 'ended_at' => now()->subMinute()->timestamp]],
        ])->assertOk();

        $this->assertSame('canceled', Account::first()->status);
    }

    public function test_payment_failure_with_a_retry_enters_grace_and_emails(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $account = $this->accountWithSubscription('active');
        $next = now()->addDays(3)->timestamp;

        $this->sendEvent([
            'id' => 'evt_4', 'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_1', 'next_payment_attempt' => $next]],
        ])->assertOk();

        $account->refresh();
        $this->assertSame('past_due', $account->status);
        $this->assertNotNull($account->grace_ends_at);
        Notification::assertSentTo($admin, PaymentFailedNotification::class);
    }

    public function test_payment_failure_without_a_retry_suspends(): void
    {
        Notification::fake();
        $this->admin();
        $account = $this->accountWithSubscription('active');

        $this->sendEvent([
            'id' => 'evt_5', 'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_1', 'next_payment_attempt' => null]],
        ])->assertOk();

        $this->assertSame('suspended', $account->fresh()->status);
    }

    public function test_unknown_events_are_skipped_not_failed(): void
    {
        $this->sendEvent(['id' => 'evt_6', 'type' => 'customer.updated', 'data' => ['object' => []]])->assertOk();
        $this->assertDatabaseHas('webhook_events', ['stripe_id' => 'evt_6', 'status' => 'skipped']);
    }

    public function test_webhook_dashboard_is_gated_and_lists_events_with_retry(): void
    {
        WebhookEvent::factory()->create(['stripe_id' => 'evt_failed', 'status' => 'failed', 'type' => 'invoice.paid',
            'payload' => ['id' => 'evt_failed', 'type' => 'invoice.paid', 'data' => ['object' => ['customer' => 'cus_1']]]]);
        Account::factory()->create(['stripe_id' => 'cus_1']);

        // Viewer is blocked.
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/admin/webhooks')->assertForbidden();

        // Admin sees it and can retry a failed event.
        Livewire::actingAs($this->admin())
            ->test(WebhookEventsPage::class)
            ->assertOk()
            ->assertSee('evt_failed')
            ->call('retry', WebhookEvent::first()->id);

        $this->assertSame('processed', WebhookEvent::first()->status);
    }
}
