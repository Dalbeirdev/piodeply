<?php

namespace Tests\Feature;

use App\Livewire\Admin\BillingDashboard;
use App\Models\Client;
use App\Models\User;
use App\Services\BillingMetricsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Next payments" — money expected in, which the billing section had no view
 * of at all. Only live subscriptions with a known renewal date qualify.
 */
class UpcomingRenewalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function client(string $name, array $billing): Client
    {
        $client = Client::factory()->create(['company_name' => $name]);
        $client->forceFill($billing)->save();

        return $client;
    }

    private function metrics(): BillingMetricsService
    {
        return app(BillingMetricsService::class);
    }

    public function test_renewals_are_listed_soonest_first(): void
    {
        $this->client('Later Ltd', ['subscription_status' => 'active', 'subscription_cents' => 4800, 'subscription_period_end' => now()->addDays(20)]);
        $this->client('Sooner Ltd', ['subscription_status' => 'active', 'subscription_cents' => 1600, 'subscription_period_end' => now()->addDays(2)]);

        $names = $this->metrics()->upcomingRenewals()->pluck('company_name')->all();

        $this->assertSame(['Sooner Ltd', 'Later Ltd'], $names);
        $this->assertSame(6400, $this->metrics()->upcomingRenewalCents());
    }

    public function test_a_trial_counts_as_an_upcoming_first_charge(): void
    {
        $this->client('Trialling Ltd', ['subscription_status' => 'trialing', 'subscription_cents' => 1600, 'subscription_period_end' => now()->addDays(5)]);

        $this->assertCount(1, $this->metrics()->upcomingRenewals());
    }

    public function test_cancelled_and_dateless_subscriptions_are_excluded(): void
    {
        $this->client('Gone Ltd', ['subscription_status' => 'canceled', 'subscription_cents' => 9900, 'subscription_period_end' => now()->addDay()]);
        $this->client('Unknown Ltd', ['subscription_status' => 'active', 'subscription_cents' => 9900, 'subscription_period_end' => null]);

        $this->assertCount(0, $this->metrics()->upcomingRenewals());
        $this->assertSame(0, $this->metrics()->upcomingRenewalCents());
    }

    public function test_the_overview_shows_who_is_due_next(): void
    {
        $this->client('Acme IT', ['subscription_status' => 'active', 'subscription_cents' => 4800, 'subscription_period_end' => now()->addDays(3)]);

        Livewire::actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value)))
            ->test(BillingDashboard::class)
            ->assertOk()
            ->assertSee('Next payments')
            ->assertSee('Acme IT')
            ->assertSee('48.00');
    }

    public function test_an_empty_billing_section_explains_itself(): void
    {
        Livewire::actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value)))
            ->test(BillingDashboard::class)
            ->assertOk()
            ->assertSee('No renewals scheduled');
    }
}
