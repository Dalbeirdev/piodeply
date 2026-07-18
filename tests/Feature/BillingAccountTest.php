<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Computer;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BillingAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_current_returns_a_single_account(): void
    {
        $a = Account::current();
        $b = Account::current();

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Account::count());
    }

    public function test_effective_device_limit_follows_the_plan_then_the_override(): void
    {
        Computer::factory()->count(3)->create();
        $plan = Plan::factory()->create(['device_limit' => 100]);
        $account = Account::factory()->create(['plan_id' => $plan->id, 'device_limit' => null]);

        $this->assertSame(3, $account->deviceCount());
        $this->assertSame(100, $account->effectiveDeviceLimit());
        $this->assertFalse($account->isOverDeviceLimit());
        $this->assertSame(97, $account->remainingDevices());

        // An admin override wins over the plan and can put the account over.
        $account->update(['device_limit' => 2, 'device_limit_overridden' => true]);
        $this->assertSame(2, $account->effectiveDeviceLimit());
        $this->assertTrue($account->isOverDeviceLimit());
        $this->assertSame(0, $account->remainingDevices());
    }

    public function test_apply_plan_copies_terms_and_respects_an_override(): void
    {
        $service = app(SubscriptionService::class);
        $plan = Plan::factory()->create(['device_limit' => 250]);
        $account = Account::factory()->create();

        $service->applyPlan($account, $plan, 'month', 'trialing');
        $account->refresh();

        $this->assertSame($plan->id, $account->plan_id);
        $this->assertSame('month', $account->billing_interval);
        $this->assertSame('trialing', $account->status);
        $this->assertSame(250, $account->device_limit);

        // With an override pinned, applying a plan must not move the limit.
        $account->update(['device_limit' => 999, 'device_limit_overridden' => true]);
        $service->applyPlan($account, $plan, 'year', 'active');
        $account->refresh();

        $this->assertSame(999, $account->device_limit);
        $this->assertSame('year', $account->billing_interval);
        $this->assertSame('active', $account->status);
    }

    public function test_prepaid_cards_are_rejected(): void
    {
        $service = Mockery::mock(SubscriptionService::class)->makePartial();
        $service->shouldReceive('cardFunding')->with('pm_prepaid')->andReturn('prepaid');
        $service->shouldReceive('cardFunding')->with('pm_credit')->andReturn('credit');

        $this->expectException(RuntimeException::class);
        $service->assertCardAcceptable('pm_prepaid');
    }

    public function test_a_normal_card_is_accepted(): void
    {
        $service = Mockery::mock(SubscriptionService::class)->makePartial();
        $service->shouldReceive('cardFunding')->andReturn('credit');

        $service->assertCardAcceptable('pm_credit');
        $this->assertTrue(true); // no exception thrown
    }

    public function test_state_snapshot_reflects_a_fresh_account(): void
    {
        Computer::factory()->count(5)->create();
        $plan = Plan::factory()->create(['device_limit' => 100]);
        $account = Account::factory()->create(['plan_id' => $plan->id]);

        $state = app(SubscriptionService::class)->state($account);

        $this->assertFalse($state['subscribed']);
        $this->assertFalse($state['on_trial']);
        $this->assertSame(5, $state['device_count']);
        $this->assertSame(100, $state['device_limit']);
        $this->assertFalse($state['over_limit']);
    }
}
