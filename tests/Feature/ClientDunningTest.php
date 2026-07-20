<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Mail\ClientBillingMail;
use App\Models\Client;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The dunning story, end to end: failure → immediate client email →
 * paced reminders → suspension when grace closes → automatic restore on
 * payment — and never touching a suspension an operator made by hand.
 */
class ClientDunningTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        Mail::fake();
    }

    private function stripeEvent(string $type, array $object)
    {
        $payload = json_encode(['type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::WEBHOOK_SECRET);

        return $this->call('POST', '/billing/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function subscribedClient(): Client
    {
        return tap(Client::factory()->create(['billing_email' => 'accounts@customer.test']), fn (Client $c) => $c->forceFill([
            'stripe_customer_id'     => 'cus_123',
            'stripe_subscription_id' => 'sub_123',
            'subscription_status'    => 'active',
            'subscription_machines'  => 150,
            'subscription_cents'     => 6800,
        ])->save());
    }

    public function test_the_first_failure_emails_the_client_immediately(): void
    {
        $client = $this->subscribedClient();

        $this->stripeEvent('invoice.payment_failed', ['subscription' => 'sub_123', 'amount_due' => 6800])->assertOk();

        Mail::assertSent(ClientBillingMail::class, fn ($mail) => $mail->stage === 'failed'
            && $mail->hasTo('accounts@customer.test'));

        $client->refresh();
        $this->assertSame(1, $client->dunning_stage);
        $this->assertNotNull($client->subscription_past_due_since);
    }

    public function test_repeat_failures_in_the_same_stretch_do_not_spam(): void
    {
        $client = $this->subscribedClient();

        // Stripe retries and fails again days later — the clock and the
        // first notice must not reset or repeat.
        $this->stripeEvent('invoice.payment_failed', ['subscription' => 'sub_123']);
        $firstSince = $client->fresh()->subscription_past_due_since;

        $this->travel(2)->days();
        $this->stripeEvent('invoice.payment_failed', ['subscription' => 'sub_123']);

        Mail::assertSent(ClientBillingMail::class, 1);
        $this->assertTrue($firstSince->equalTo($client->fresh()->subscription_past_due_since));
    }

    public function test_reminders_are_paced_and_carry_the_countdown(): void
    {
        $client = $this->subscribedClient();
        $this->stripeEvent('invoice.payment_failed', ['subscription' => 'sub_123']);

        // Next day: too soon for a reminder.
        $this->travel(1)->days();
        $this->artisan('billing:client-dunning')->assertOk();
        Mail::assertSent(ClientBillingMail::class, 1); // still only the first notice

        // Day 4: reminder due, with (14 - 4) days left.
        $this->travel(3)->days();
        $this->artisan('billing:client-dunning')->assertOk();
        Mail::assertSent(ClientBillingMail::class, fn ($mail) => $mail->stage === 'reminder' && $mail->daysLeft === 10);
        $this->assertSame(2, $client->fresh()->dunning_stage);
    }

    public function test_grace_expiry_suspends_once_and_emails(): void
    {
        $client = $this->subscribedClient();
        $this->stripeEvent('invoice.payment_failed', ['subscription' => 'sub_123']);

        $this->travel(15)->days();
        $this->artisan('billing:client-dunning')->assertOk();

        $client->refresh();
        $this->assertSame(ClientStatus::Suspended, $client->status);
        $this->assertNotNull($client->billing_suspended_at);
        Mail::assertSent(ClientBillingMail::class, fn ($mail) => $mail->stage === 'suspended');

        // Running again must not re-suspend or re-email.
        $this->artisan('billing:client-dunning')->assertOk();
        Mail::assertSent(ClientBillingMail::class, 2); // failed + suspended, nothing more
    }

    public function test_payment_restores_a_billing_suspension_automatically(): void
    {
        $client = $this->subscribedClient();
        $this->stripeEvent('invoice.payment_failed', ['subscription' => 'sub_123']);
        $this->travel(15)->days();
        $this->artisan('billing:client-dunning');

        $this->stripeEvent('invoice.paid', [
            'subscription' => 'sub_123',
            'amount_paid'  => 6800,
            'lines'        => ['data' => [['period' => ['end' => now()->addMonth()->timestamp]]]],
        ])->assertOk();

        $client->refresh();
        $this->assertSame(ClientStatus::Active, $client->status);
        $this->assertNull($client->billing_suspended_at);
        $this->assertSame(0, $client->dunning_stage);
        Mail::assertSent(ClientBillingMail::class, fn ($mail) => $mail->stage === 'restored');
    }

    public function test_a_manual_suspension_is_never_lifted_by_a_payment(): void
    {
        $client = $this->subscribedClient();
        // Operator suspended by hand: status set, but no billing marker.
        $client->forceFill(['status' => ClientStatus::Suspended])->save();

        $this->stripeEvent('invoice.paid', [
            'subscription' => 'sub_123',
            'amount_paid'  => 6800,
            'lines'        => ['data' => [['period' => ['end' => now()->addMonth()->timestamp]]]],
        ])->assertOk();

        $this->assertSame(ClientStatus::Suspended, $client->fresh()->status, 'a human suspension is a human decision');
        Mail::assertNotSent(ClientBillingMail::class);
    }
}
