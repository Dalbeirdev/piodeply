<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Services\AffiliateService;
use App\Services\WebhookService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_an_event_for_an_unknown_customer_never_touches_the_account(): void
    {
        // A live, active account with its own Stripe customer id.
        $account = Account::current();
        $account->forceFill(['status' => 'active', 'stripe_id' => 'cus_known'])->save();

        // A signature-valid failed invoice for a DIFFERENT customer must be
        // skipped — not applied to (and certainly not suspending) our account.
        $outcome = app(WebhookService::class)->handle([
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_stranger', 'next_payment_attempt' => null]],
        ]);

        $this->assertSame('skipped', $outcome);
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_a_paid_invoice_for_an_unknown_customer_records_no_revenue(): void
    {
        Account::current()->forceFill(['stripe_id' => 'cus_known'])->save();

        app(WebhookService::class)->handle([
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_x', 'customer' => 'cus_stranger', 'amount_paid' => 9999]],
        ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_two_withdrawals_cannot_overdraw_the_balance(): void
    {
        $affiliate = Affiliate::factory()->create();
        AffiliateCommission::create([
            'affiliate_id' => $affiliate->id, 'amount_cents' => 1000, 'base_amount_cents' => 5000, 'status' => 'approved',
        ]);
        $service = app(AffiliateService::class);

        $service->requestWithdrawal($affiliate, 700); // leaves 300 available

        $this->expectException(\RuntimeException::class);
        $service->requestWithdrawal($affiliate, 700); // would overdraw
    }

    public function test_commission_is_based_on_the_pre_tax_subtotal(): void
    {
        $affiliate = Affiliate::factory()->create(['commission_rate' => 20]);
        Account::current()->forceFill(['stripe_id' => 'cus_1', 'referred_by_affiliate_id' => $affiliate->id])->save();

        // amount_paid ($60, tax-inclusive) vs subtotal ($50). 20% of subtotal = $10.
        app(WebhookService::class)->handle([
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_tax', 'customer' => 'cus_1', 'subtotal' => 5000, 'amount_paid' => 6000]],
        ]);

        $this->assertDatabaseHas('affiliate_commissions', ['affiliate_id' => $affiliate->id, 'amount_cents' => 1000]);
    }
}
