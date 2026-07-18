<?php

namespace Tests\Feature;

use App\Livewire\Admin\Affiliates as AffiliatesAdmin;
use App\Livewire\Affiliate\Dashboard as AffiliateDashboard;
use App\Models\Account;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\WebhookService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AffiliateSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function service(): AffiliateService
    {
        return app(AffiliateService::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    public function test_resolve_finds_only_approved_codes(): void
    {
        Affiliate::factory()->create(['code' => 'john']);
        Affiliate::factory()->pending()->create(['code' => 'jane']);

        $this->assertSame('john', $this->service()->resolve('JOHN')?->code); // case-insensitive
        $this->assertNull($this->service()->resolve('jane'));                // pending
        $this->assertNull($this->service()->resolve('nobody'));
    }

    public function test_clicks_are_recorded_only_for_real_affiliates(): void
    {
        Affiliate::factory()->create(['code' => 'john']);

        $this->assertNotNull($this->service()->recordClick('john', '1.2.3.4', 'pricing'));
        $this->assertNull($this->service()->recordClick('ghost'));
        $this->assertDatabaseCount('affiliate_clicks', 1);
    }

    public function test_referral_link_sets_a_cookie_and_logs_a_click(): void
    {
        Affiliate::factory()->create(['code' => 'john']);

        $this->get('/pricing?ref=john')
            ->assertOk()
            ->assertCookie('pd_ref', 'john');

        $this->assertDatabaseCount('affiliate_clicks', 1);
    }

    public function test_account_is_stamped_with_the_first_referrer_only(): void
    {
        $a1 = Affiliate::factory()->create(['code' => 'john']);
        $a2 = Affiliate::factory()->create(['code' => 'jane']);
        $account = Account::factory()->create();

        $this->service()->stampAccountReferrer($account, 'john');
        $this->service()->stampAccountReferrer($account, 'jane'); // must not overwrite

        $this->assertSame($a1->id, $account->fresh()->referred_by_affiliate_id);
    }

    public function test_commission_maths(): void
    {
        $percent = Affiliate::factory()->create(['commission_type' => 'percentage', 'commission_rate' => 20]);
        $fixed = Affiliate::factory()->fixed(5000)->create();

        $this->assertSame(1000, $percent->commissionFor(5000)); // 20% of $50
        $this->assertSame(5000, $fixed->commissionFor(99999));   // flat $50
    }

    public function test_commission_accrues_for_a_referred_account_and_is_idempotent(): void
    {
        $affiliate = Affiliate::factory()->create(['commission_rate' => 20]);
        $account = Account::factory()->create(['referred_by_affiliate_id' => $affiliate->id]);

        $this->service()->accrueCommission($account, 'in_1', 5000);
        $this->service()->accrueCommission($account, 'in_1', 5000); // redelivered invoice

        $this->assertDatabaseCount('affiliate_commissions', 1);
        $this->assertSame(1000, AffiliateCommission::first()->amount_cents);
    }

    public function test_one_time_affiliate_earns_only_on_the_first_invoice(): void
    {
        $affiliate = Affiliate::factory()->create(['recurring' => false]);
        $account = Account::factory()->create(['referred_by_affiliate_id' => $affiliate->id]);

        $this->service()->accrueCommission($account, 'in_1', 5000);
        $this->service()->accrueCommission($account, 'in_2', 5000); // second cycle: no commission

        $this->assertDatabaseCount('affiliate_commissions', 1);
    }

    public function test_unreferred_accounts_accrue_nothing(): void
    {
        $account = Account::factory()->create(['referred_by_affiliate_id' => null]);
        $this->assertNull($this->service()->accrueCommission($account, 'in_1', 5000));
    }

    public function test_invoice_paid_webhook_accrues_a_commission(): void
    {
        $affiliate = Affiliate::factory()->create(['commission_rate' => 25]);
        // stripe_id is a Cashier-guarded column, so set it with forceFill.
        Account::current()->forceFill(['stripe_id' => 'cus_1', 'referred_by_affiliate_id' => $affiliate->id])->save();

        app(WebhookService::class)->handle([
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_99', 'customer' => 'cus_1', 'amount_paid' => 4800, 'currency' => 'usd']],
        ]);

        $this->assertDatabaseHas('affiliate_commissions', ['affiliate_id' => $affiliate->id, 'amount_cents' => 1200]);
    }

    public function test_balance_approve_and_payout_flow(): void
    {
        $affiliate = Affiliate::factory()->create();
        $account = Account::factory()->create(['referred_by_affiliate_id' => $affiliate->id]);
        $commission = $this->service()->accrueCommission($account, 'in_1', 5000); // $10

        $this->assertSame(0, $affiliate->availableBalanceCents()); // pending, not yet approved

        $this->service()->approve($commission);
        $this->assertSame(1000, $affiliate->fresh()->availableBalanceCents());

        $withdrawal = $this->service()->requestWithdrawal($affiliate, 1000);
        $this->service()->payWithdrawal($withdrawal);

        $this->assertSame('paid', $commission->fresh()->status);
        $this->assertSame(0, $affiliate->fresh()->availableBalanceCents());
    }

    public function test_admin_can_create_and_approve_affiliates_and_gating_holds(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/admin/affiliates')->assertForbidden();

        Livewire::actingAs($this->admin())->test(AffiliatesAdmin::class)
            ->call('create')
            ->set('name', 'John Doe')->set('email', 'john@x.test')->set('code', 'john')
            ->set('commissionType', 'percentage')->set('commissionRate', 30)
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('affiliates', ['code' => 'john', 'commission_rate' => 30]);
    }

    public function test_commission_csv_export_is_gated(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/admin/affiliates/export')->assertForbidden();

        $this->actingAs($this->admin())->get('/admin/affiliates/export')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_affiliate_dashboard_shows_link_for_a_linked_user_and_a_notice_otherwise(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(AffiliateDashboard::class)->assertSee("not an affiliate");

        Affiliate::factory()->create(['user_id' => $user->id, 'code' => 'john']);
        Livewire::actingAs($user)->test(AffiliateDashboard::class)->assertSee('ref=john');
    }
}
