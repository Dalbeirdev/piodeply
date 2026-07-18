<?php

namespace Tests\Feature;

use App\Livewire\Admin\BillingDashboard;
use App\Models\Account;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\BillingMetricsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function metrics(): BillingMetricsService
    {
        return app(BillingMetricsService::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    public function test_mrr_sums_monthly_equivalents_across_active_accounts(): void
    {
        $monthly = Plan::factory()->create(['monthly_price_cents' => 4800, 'yearly_price_cents' => 48000]);
        $yearly = Plan::factory()->create(['monthly_price_cents' => 6000, 'yearly_price_cents' => 48000]);

        Account::factory()->create(['status' => 'active', 'plan_id' => $monthly->id, 'billing_interval' => 'month']);
        Account::factory()->create(['status' => 'active', 'plan_id' => $yearly->id, 'billing_interval' => 'year']); // 48000/12 = 4000
        Account::factory()->create(['status' => 'trialing', 'plan_id' => $monthly->id, 'billing_interval' => 'month']); // trial ≠ MRR

        $this->assertSame(8800, $this->metrics()->mrrCents());   // 4800 + 4000
        $this->assertSame(8800 * 12, $this->metrics()->arrCents());
    }

    public function test_revenue_total_and_series_come_from_paid_payments(): void
    {
        Payment::create(['status' => 'paid', 'amount_total' => 5000, 'currency' => 'usd']);
        Payment::create(['status' => 'paid', 'amount_total' => 3000, 'currency' => 'usd']);
        Payment::create(['status' => 'pending', 'amount_total' => 9999, 'currency' => 'usd']); // not counted

        $this->assertSame(8000, $this->metrics()->totalRevenueCents());

        $series = $this->metrics()->revenueSeries(12);
        $this->assertCount(12, $series);
        $this->assertSame(8000, end($series)['cents']); // current month
    }

    public function test_ltv_divides_revenue_by_paying_customers(): void
    {
        $plan = Plan::factory()->create();
        Account::factory()->count(2)->create(['plan_id' => $plan->id, 'status' => 'active']);
        Payment::create(['status' => 'paid', 'amount_total' => 10000, 'currency' => 'usd']);

        $this->assertSame(5000, $this->metrics()->lifetimeValueCents()); // 10000 / 2
    }

    public function test_coupon_and_affiliate_rollups(): void
    {
        $coupon = Coupon::factory()->create(['is_active' => true]);
        CouponRedemption::create(['coupon_id' => $coupon->id, 'amount_discounted_cents' => 500, 'redeemed_at' => now()]);

        $affiliate = Affiliate::factory()->create();
        AffiliateCommission::create(['affiliate_id' => $affiliate->id, 'amount_cents' => 1200, 'status' => 'approved', 'base_amount_cents' => 4800]);

        $this->assertSame(1, $this->metrics()->couponStats()['redemptions']);
        $this->assertSame(500, $this->metrics()->couponStats()['discount_cents']);
        $this->assertSame(1200, $this->metrics()->affiliateStats()['approved_cents']);
    }

    public function test_churn_and_status_breakdown(): void
    {
        Account::factory()->create(['status' => 'active']);
        Account::factory()->create(['status' => 'canceled']);

        $this->assertSame(50, $this->metrics()->churnPercent()); // 1 cancelled of 2
        $this->assertSame(1, $this->metrics()->statusBreakdown()['active']);
    }

    public function test_dashboard_is_gated_and_renders(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/admin/billing-overview')->assertForbidden();

        Livewire::actingAs($this->admin())->test(BillingDashboard::class)
            ->assertOk()
            ->assertSee('MRR')
            ->assertSee('Revenue — last 12 months');
    }

    public function test_payments_export_is_gated(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/admin/billing-overview/export')->assertForbidden();

        $this->actingAs($this->admin())->get('/admin/billing-overview/export')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
