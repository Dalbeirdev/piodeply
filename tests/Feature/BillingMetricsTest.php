<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Services\BillingMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The billing overview counts CLIENTS. It used to count accounts — a table
 * nothing in the app ever inserts into — so every tile read zero while real
 * customers were paying. These tests exist so that cannot come back.
 */
class BillingMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $billing): Client
    {
        $client = Client::factory()->create();
        $client->forceFill($billing)->save();

        return $client;
    }

    private function metrics(): BillingMetricsService
    {
        return app(BillingMetricsService::class);
    }

    public function test_mrr_sums_the_paying_clients(): void
    {
        $this->client(['subscription_status' => 'active', 'subscription_cents' => 4800, 'stripe_subscription_id' => 'sub_a']);
        $this->client(['subscription_status' => 'past_due', 'subscription_cents' => 1600, 'stripe_subscription_id' => 'sub_b']);
        // Neither of these is revenue in force.
        $this->client(['subscription_status' => 'trialing', 'subscription_cents' => 9900, 'stripe_subscription_id' => 'sub_c']);
        $this->client(['subscription_status' => 'canceled', 'subscription_cents' => 9900, 'stripe_subscription_id' => 'sub_d']);

        $this->assertSame(6400, $this->metrics()->mrrCents(), 'active + past_due only');
        $this->assertSame(6400 * 12, $this->metrics()->arrCents());
    }

    public function test_a_paying_customer_is_never_invisible(): void
    {
        // The exact shape that used to report zero across the board.
        $this->client(['subscription_status' => 'active', 'subscription_cents' => 4800, 'stripe_subscription_id' => 'sub_live']);
        Payment::create(['status' => 'paid', 'amount_total' => 4800, 'currency' => 'usd', 'customer_email' => 'a@b.c']);

        $summary = $this->metrics()->summary();

        $this->assertGreaterThan(0, $summary['mrr_cents']);
        $this->assertGreaterThan(0, $summary['arr_cents']);
        $this->assertGreaterThan(0, $summary['revenue_cents']);
        $this->assertGreaterThan(0, $summary['ltv_cents']);
        $this->assertSame(['active' => 1], $summary['status']);
    }

    public function test_the_funnel_counts(): void
    {
        $this->client(['subscription_status' => 'trialing', 'stripe_subscription_id' => 'sub_t']);
        $this->client(['subscription_status' => 'canceled', 'stripe_subscription_id' => 'sub_x']);
        $this->client(['subscription_status' => 'past_due', 'stripe_subscription_id' => 'sub_p']);
        $this->client(['subscription_status' => 'unpaid', 'stripe_subscription_id' => 'sub_u']);

        $m = $this->metrics();
        $this->assertSame(1, $m->activeTrials());
        $this->assertSame(1, $m->cancelledCount());
        $this->assertSame(2, $m->paymentIssues(), 'past_due and unpaid both mean charging is failing');
    }

    public function test_expired_trials_are_ones_that_lapsed_without_paying(): void
    {
        $this->client(['subscription_status' => 'canceled', 'subscription_period_end' => now()->subDay()]);
        // Still running, so not expired.
        $this->client(['subscription_status' => 'trialing', 'subscription_period_end' => now()->addDay()]);
        // Converted, so not expired.
        $this->client(['subscription_status' => 'active', 'subscription_period_end' => now()->subDay()]);

        $this->assertSame(1, $this->metrics()->expiredTrials());
    }

    public function test_churn_is_cancelled_over_everyone_who_ever_subscribed(): void
    {
        $this->client(['subscription_status' => 'active', 'stripe_subscription_id' => 'sub_1']);
        $this->client(['subscription_status' => 'active', 'stripe_subscription_id' => 'sub_2']);
        $this->client(['subscription_status' => 'active', 'stripe_subscription_id' => 'sub_3']);
        $this->client(['subscription_status' => 'canceled', 'stripe_subscription_id' => 'sub_4']);

        $this->assertSame(25, $this->metrics()->churnPercent());
    }

    public function test_ltv_divides_revenue_across_everyone_who_ever_subscribed(): void
    {
        $this->client(['subscription_status' => 'active', 'stripe_subscription_id' => 'sub_1']);
        // A churned customer still counts, or LTV would rise as people leave.
        $this->client(['subscription_status' => 'canceled', 'stripe_subscription_id' => 'sub_2']);
        Payment::create(['status' => 'paid', 'amount_total' => 10000, 'currency' => 'usd', 'customer_email' => 'a@b.c']);

        $this->assertSame(5000, $this->metrics()->lifetimeValueCents());
    }

    public function test_clients_that_never_subscribed_are_not_counted(): void
    {
        Client::factory()->count(3)->create(); // no billing fields at all

        $m = $this->metrics();
        $this->assertSame(0, $m->mrrCents());
        $this->assertSame([], $m->statusBreakdown());
        $this->assertSame(0, $m->churnPercent());
    }

    public function test_an_empty_install_reports_zeroes_not_errors(): void
    {
        $summary = $this->metrics()->summary();

        $this->assertSame(0, $summary['mrr_cents']);
        $this->assertSame(0, $summary['churn_percent']);
        $this->assertSame([], $summary['status']);
    }
}
