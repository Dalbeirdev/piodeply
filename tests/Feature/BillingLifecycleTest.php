<?php

namespace Tests\Feature;

use App\Livewire\Billing\Subscription as SubscriptionComponent;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Build an account with a local Cashier subscription row (no Stripe call). */
    private function accountWithSubscription(array $subAttrs = [], array $accountAttrs = []): Account
    {
        $account = Account::factory()->create($accountAttrs);
        $account->subscriptions()->create(array_merge([
            'type'         => 'default',
            'stripe_id'    => 'sub_' . uniqid(),
            'stripe_status'=> 'active',
            'stripe_price' => 'price_123',
            'quantity'     => 1,
        ], $subAttrs));

        return $account->refresh();
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    public function test_derive_status_with_no_subscription_is_none(): void
    {
        $this->assertSame('none', $this->service()->deriveStatus(Account::factory()->create()));
    }

    public function test_derive_status_active(): void
    {
        $account = $this->accountWithSubscription(['stripe_status' => 'active']);
        $this->assertSame('active', $this->service()->deriveStatus($account));
    }

    public function test_derive_status_trialing(): void
    {
        $account = $this->accountWithSubscription([
            'stripe_status' => 'trialing',
            'trial_ends_at' => now()->addDays(10),
        ]);
        $this->assertSame('trialing', $this->service()->deriveStatus($account));
    }

    public function test_derive_status_grace_when_cancelled_but_not_expired(): void
    {
        $account = $this->accountWithSubscription(['ends_at' => now()->addDays(5)]);
        $this->assertSame('grace', $this->service()->deriveStatus($account));
    }

    public function test_derive_status_canceled_when_period_over(): void
    {
        $account = $this->accountWithSubscription(['ends_at' => now()->subDay()]);
        $this->assertSame('canceled', $this->service()->deriveStatus($account));
    }

    public function test_derive_status_past_due(): void
    {
        $account = $this->accountWithSubscription(['stripe_status' => 'past_due']);
        $this->assertSame('past_due', $this->service()->deriveStatus($account));
    }

    public function test_derive_status_paused_overrides_active(): void
    {
        $account = $this->accountWithSubscription(['stripe_status' => 'active'], ['paused_at' => now()]);
        $this->assertSame('paused', $this->service()->deriveStatus($account));
    }

    public function test_state_flags_for_an_active_subscription(): void
    {
        $account = $this->accountWithSubscription(['stripe_status' => 'active']);
        $state = $this->service()->state($account);

        $this->assertTrue($state['can_change']);
        $this->assertTrue($state['can_cancel']);
        $this->assertTrue($state['can_pause']);
        $this->assertFalse($state['can_resume']);
    }

    public function test_state_flags_for_a_grace_period(): void
    {
        $account = $this->accountWithSubscription(['ends_at' => now()->addDays(5)]);
        $state = $this->service()->state($account);

        $this->assertTrue($state['can_resume']);
        $this->assertFalse($state['can_cancel']);
        $this->assertFalse($state['can_change']);
    }

    public function test_resume_without_a_grace_period_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->resume($this->accountWithSubscription(['stripe_status' => 'active']));
    }

    public function test_change_plan_without_a_subscription_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->changePlan(Account::factory()->create(), Plan::factory()->create(), 'month');
    }

    public function test_lifecycle_actions_are_gated_and_surface_errors(): void
    {
        Plan::factory()->create();
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));

        // Resume on an account not in grace: the guard throws, the component
        // catches it into a friendly error rather than blowing up.
        $account = $this->accountWithSubscription(['stripe_status' => 'active']);

        Livewire::actingAs($admin)
            ->test(SubscriptionComponent::class)
            ->call('resume')
            ->assertSet('errorMessage', fn ($v) => $v !== null && str_contains($v, 'grace'));
    }
}
