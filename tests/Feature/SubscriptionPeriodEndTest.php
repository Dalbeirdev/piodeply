<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\ClientSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stripe moved current_period_end off the subscription and onto each
 * subscription item. Reading the old top-level field returns null on the
 * current API version, which blanked every "Renews" / "Next payment" date
 * and made a subscription scheduled to cancel look like one that never ends.
 */
class SubscriptionPeriodEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_period_end_from_the_subscription_item(): void
    {
        $payload = [
            'id'    => 'sub_1',
            'items' => ['data' => [['current_period_end' => 1788692131]]],
        ];

        $this->assertSame(1788692131, ClientSubscriptionService::periodEnd($payload));
    }

    public function test_it_falls_back_to_the_top_level_field(): void
    {
        // Events from an older API version still carry it at the top.
        $payload = ['id' => 'sub_1', 'current_period_end' => 1700000000, 'items' => ['data' => [[]]]];

        $this->assertSame(1700000000, ClientSubscriptionService::periodEnd($payload));
    }

    public function test_the_item_wins_when_both_are_present(): void
    {
        $payload = [
            'id'                 => 'sub_1',
            'current_period_end' => 1700000000,
            'items'              => ['data' => [['current_period_end' => 1788692131]]],
        ];

        $this->assertSame(1788692131, ClientSubscriptionService::periodEnd($payload));
    }

    public function test_it_is_null_when_stripe_sends_neither(): void
    {
        $this->assertNull(ClientSubscriptionService::periodEnd(['id' => 'sub_1']));
    }

    public function test_a_subscription_update_stores_the_renewal_date(): void
    {
        $client = Client::factory()->create();
        $client->forceFill([
            'stripe_customer_id'     => 'cus_1',
            'stripe_subscription_id' => 'sub_1',
            'subscription_status'    => 'active',
        ])->save();

        app(ClientSubscriptionService::class)->subscriptionUpdated([
            'id'     => 'sub_1',
            'status' => 'active',
            'items'  => ['data' => [[
                'current_period_end' => 1788692131,
                'price'              => ['unit_amount' => 4800],
            ]]],
        ]);

        $client->refresh();
        $this->assertNotNull($client->subscription_period_end, 'the renewal date must not be blank');
        $this->assertSame(
            date('Y-m-d', 1788692131),
            $client->subscription_period_end->format('Y-m-d')
        );
        $this->assertSame(4800, $client->subscription_cents);
    }
}
