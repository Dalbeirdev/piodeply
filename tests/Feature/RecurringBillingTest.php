<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\Client;
use App\Models\Signup;
use App\Models\User;
use App\Services\SignupApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The recurring life of a wizard-born subscription: Stripe charges monthly
 * on its own; these prove the app's mirror of it stays honest — renewals,
 * failures, cancellations, and the tenant's own billing page.
 */
class RecurringBillingTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /** POST a signed Stripe event to the webhook. */
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

    /** A client whose subscription is already linked (post-approval state). */
    private function subscribedClient(): Client
    {
        return tap(Client::factory()->create(), fn (Client $c) => $c->forceFill([
            'stripe_customer_id'     => 'cus_123',
            'stripe_subscription_id' => 'sub_123',
            'subscription_status'    => 'active',
            'subscription_machines'  => 150,
            'subscription_cents'     => 6800,
        ])->save());
    }

    public function test_checkout_completion_records_the_subscription_on_the_signup(): void
    {
        $signup = Signup::factory()->create(['status' => Signup::STATUS_PENDING_PAYMENT]);

        $this->stripeEvent('checkout.session.completed', [
            'id'             => 'cs_1',
            'customer'       => 'cus_9',
            'subscription'   => 'sub_9',
            'payment_status' => 'paid',
            'metadata'       => ['signup_id' => $signup->id, 'machines' => $signup->machines],
        ])->assertOk();

        $signup->refresh();
        $this->assertSame('cus_9', $signup->stripe_customer_id);
        $this->assertSame('sub_9', $signup->stripe_subscription_id);
        $this->assertSame(Signup::STATUS_PAID, $signup->status);
    }

    public function test_approval_copies_the_subscription_to_the_new_client(): void
    {
        Mail::fake();
        $signup = Signup::factory()->create([
            'status'                 => Signup::STATUS_PAID,
            'paid_at'                => now(),
            'stripe_customer_id'     => 'cus_42',
            'stripe_subscription_id' => 'sub_42',
        ]);
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        app(SignupApprovalService::class)->approve($signup, $admin);

        $client = $signup->fresh()->client;
        $this->assertSame('cus_42', $client->stripe_customer_id);
        $this->assertSame('sub_42', $client->stripe_subscription_id);
        $this->assertSame('active', $client->subscription_status);
        $this->assertSame($signup->machines, $client->subscription_machines);
    }

    public function test_a_paid_renewal_refreshes_status_and_period_end(): void
    {
        $client = $this->subscribedClient();
        $client->forceFill(['subscription_status' => 'past_due'])->save();
        $periodEnd = now()->addMonth()->timestamp;

        $this->stripeEvent('invoice.paid', [
            'subscription' => 'sub_123',
            'amount_paid'  => 6800,
            'lines'        => ['data' => [['period' => ['end' => $periodEnd]]]],
        ])->assertOk();

        $client->refresh();
        $this->assertSame('active', $client->subscription_status);
        $this->assertSame(6800, $client->subscription_cents);
        $this->assertSame($periodEnd, $client->subscription_period_end->timestamp);
    }

    public function test_a_failed_payment_marks_the_client_past_due(): void
    {
        $client = $this->subscribedClient();

        $this->stripeEvent('invoice.payment_failed', [
            'subscription'  => 'sub_123',
            'amount_due'    => 6800,
            'attempt_count' => 2,
        ])->assertOk();

        $this->assertSame('past_due', $client->fresh()->subscription_status);
    }

    public function test_a_cancellation_marks_the_client_canceled(): void
    {
        $client = $this->subscribedClient();

        $this->stripeEvent('customer.subscription.deleted', ['id' => 'sub_123'])->assertOk();

        $this->assertSame('canceled', $client->fresh()->subscription_status);
    }

    public function test_events_for_unknown_subscriptions_are_ignored_quietly(): void
    {
        // Stripe retries anything but a 2xx forever — an event we cannot
        // place must still be acknowledged, not errored.
        $this->stripeEvent('invoice.paid', ['subscription' => 'sub_nobody'])->assertOk();
        $this->stripeEvent('customer.subscription.deleted', ['id' => 'sub_nobody'])->assertOk();
    }

    public function test_the_tenant_sees_their_own_billing_page(): void
    {
        $client = $this->subscribedClient();
        $tenant = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Manager->value));

        Livewire::actingAs($tenant)
            ->test(\App\Livewire\Clients\TenantBilling::class)
            ->assertOk()
            ->assertSee('150 machines')
            ->assertSee('$68.00')
            ->assertSee('Manage billing');
    }

    public function test_staff_have_no_tenant_billing_page(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        $this->actingAs($admin)->get('/my-billing')->assertNotFound();
    }
}
