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

    public function test_a_trial_checkout_counts_as_payment_secured(): void
    {
        // With the 14-day trial, checkout completes WITHOUT charging:
        // payment_status is no_payment_required (card verified, Stripe
        // charges at trial end). That must advance the signup exactly like
        // a paid session — otherwise every trial sits "awaiting payment".
        $signup = Signup::factory()->create(['status' => Signup::STATUS_PENDING_PAYMENT]);

        $this->stripeEvent('checkout.session.completed', [
            'id'             => 'cs_trial',
            'customer'       => 'cus_t',
            'subscription'   => 'sub_t',
            'payment_status' => 'no_payment_required',
            'metadata'       => ['signup_id' => $signup->id, 'machines' => $signup->machines],
        ])->assertOk();

        $signup->refresh();
        $this->assertSame(Signup::STATUS_PAID, $signup->status);
        $this->assertSame('sub_t', $signup->stripe_subscription_id);
    }

    public function test_the_wizard_checkout_requests_the_promised_trial(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        \Illuminate\Support\Facades\Http::fake([
            'api.stripe.com/*' => \Illuminate\Support\Facades\Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        app(\App\Services\BillingService::class)->createCheckout(
            machines: 100, successUrl: 'https://x/s', cancelUrl: 'https://x/c',
        );

        // The marketing site promises 14 days free, card required — the
        // session Stripe receives must actually say so.
        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), '/checkout/sessions')
                && ($request->data()['subscription_data']['trial_period_days'] ?? null) == \App\Services\BillingService::TRIAL_DAYS;
        });
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

    public function test_an_owner_can_resize_their_own_subscription(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        \Illuminate\Support\Facades\Http::fake([
            'api.stripe.com/v1/subscriptions/sub_123' => \Illuminate\Support\Facades\Http::response([
                'id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1']]],
            ]),
        ]);

        $client = $this->subscribedClient();
        $owner = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Clients\TenantBilling::class)
            ->set('resizeMachines', 300)
            ->call('resize');

        $client->refresh();
        $this->assertSame(300, $client->subscription_machines);
        $this->assertSame(app(\App\Services\BillingService::class)->quoteCents(300), $client->subscription_cents);

        // The update Stripe received carries proration and the new metadata.
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/subscriptions/sub_123')
            && ($request->data()['proration_behavior'] ?? '') === 'create_prorations'
            && ($request->data()['metadata']['machines'] ?? null) == 300);
    }

    public function test_a_technician_cannot_touch_billing(): void
    {
        $client = $this->subscribedClient();
        $tech = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::Technician->value));

        Livewire::actingAs($tech)
            ->test(\App\Livewire\Clients\TenantBilling::class)
            ->set('resizeMachines', 999)
            ->call('resize')
            ->assertForbidden();

        Livewire::actingAs($tech)
            ->test(\App\Livewire\Clients\TenantBilling::class)
            ->call('openPortal')
            ->assertForbidden();

        $this->assertSame(150, $client->fresh()->subscription_machines);
    }

    public function test_resize_refuses_without_an_online_subscription(): void
    {
        $client = Client::factory()->create(); // invoiced/manual — no Stripe ids
        $owner = tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Clients\TenantBilling::class)
            ->set('resizeMachines', 50)
            ->call('resize');

        $this->assertNull($client->fresh()->subscription_machines, 'nothing changes without a Stripe subscription');
    }
}
