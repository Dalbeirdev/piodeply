<?php

namespace Tests\Feature;

use App\Livewire\Admin\Coupons as CouponsAdmin;
use App\Livewire\Billing\Subscription;
use App\Models\Account;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CouponSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function service(): CouponService
    {
        return app(CouponService::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    public function test_a_valid_active_coupon_passes_and_unknown_codes_fail(): void
    {
        Coupon::factory()->create(['code' => 'SAVE20']);

        $this->assertTrue($this->service()->validate('save20')['valid']);        // case-insensitive
        $this->assertFalse($this->service()->validate('NOPE')['valid']);
    }

    public function test_inactive_expired_and_exhausted_coupons_are_rejected(): void
    {
        $inactive = Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);
        $expired = Coupon::factory()->expired()->create(['code' => 'OLD']);
        $exhausted = Coupon::factory()->create(['code' => 'FULL', 'max_redemptions' => 2, 'times_redeemed' => 2]);

        $this->assertFalse($this->service()->validate('OFF')['valid']);
        $this->assertStringContainsString('expired', $this->service()->validate('OLD')['reason']);
        $this->assertStringContainsString('usage limit', $this->service()->validate('FULL')['reason']);
    }

    public function test_plan_specific_coupons_only_apply_to_that_plan(): void
    {
        $planA = Plan::factory()->create();
        $planB = Plan::factory()->create();
        Coupon::factory()->create(['code' => 'PLANA', 'plan_id' => $planA->id]);

        $this->assertTrue($this->service()->validate('PLANA', null, $planA)['valid']);
        $this->assertFalse($this->service()->validate('PLANA', null, $planB)['valid']);
    }

    public function test_per_customer_limit_is_enforced(): void
    {
        $account = Account::factory()->create();
        $coupon = Coupon::factory()->create(['code' => 'ONCE', 'max_per_customer' => 1]);

        $this->assertTrue($this->service()->validate('ONCE', $account)['valid']);

        $this->service()->redeem($coupon, $account);
        $this->assertStringContainsString('already used', $this->service()->validate('ONCE', $account)['reason']);
    }

    public function test_discount_preview_maths(): void
    {
        $plan = Plan::factory()->create(['monthly_price_cents' => 5000, 'yearly_price_cents' => 50000]);

        $percent = Coupon::factory()->percent(20)->make();
        $this->assertSame(1000, $this->service()->preview($percent, $plan, 'month')['discount_cents']);
        $this->assertSame(4000, $this->service()->preview($percent, $plan, 'month')['final_cents']);

        $fixed = Coupon::factory()->fixed(1500)->make();
        $this->assertSame(1500, $this->service()->preview($fixed, $plan, 'month')['discount_cents']);

        // Fixed discount never goes below zero.
        $huge = Coupon::factory()->fixed(999999)->make();
        $this->assertSame(0, $this->service()->preview($huge, $plan, 'month')['final_cents']);

        // Trial-day coupon doesn't touch the price.
        $trial = Coupon::factory()->trialDays(30)->make();
        $prev = $this->service()->preview($trial, $plan, 'month');
        $this->assertSame(0, $prev['discount_cents']);
        $this->assertSame(30, $prev['trial_extra_days']);
    }

    public function test_redeem_records_and_advances_the_counter(): void
    {
        $account = Account::factory()->create();
        $coupon = Coupon::factory()->create(['times_redeemed' => 0]);

        $this->service()->redeem($coupon, $account, 1000);

        $this->assertSame(1, $coupon->fresh()->times_redeemed);
        $this->assertDatabaseHas('coupon_redemptions', [
            'coupon_id' => $coupon->id, 'account_id' => $account->id, 'amount_discounted_cents' => 1000,
        ]);
    }

    public function test_admin_can_create_and_delete_a_coupon(): void
    {
        // Gated.
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/admin/coupons')->assertForbidden();

        Livewire::actingAs($this->admin())->test(CouponsAdmin::class)
            ->call('create')
            ->set('code', 'launch20')
            ->set('name', 'Launch promo')
            ->set('type', 'percent')
            ->set('value', 20)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('coupons', ['code' => 'LAUNCH20', 'type' => 'percent', 'value' => 20]);

        $coupon = Coupon::first();
        Livewire::actingAs($this->admin())->test(CouponsAdmin::class)->call('delete', $coupon->id);
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    public function test_percentage_over_100_is_rejected_by_the_admin_form(): void
    {
        Livewire::actingAs($this->admin())->test(CouponsAdmin::class)
            ->set('code', 'BAD')->set('name', 'x')->set('type', 'percent')->set('value', 150)
            ->call('save')
            ->assertHasErrors('value');
    }

    public function test_customer_can_preview_a_coupon_on_the_subscription_screen(): void
    {
        $plan = Plan::factory()->recommended()->create(['monthly_price_cents' => 5000]);
        Coupon::factory()->percent(25)->create(['code' => 'QUARTER']);

        Livewire::actingAs($this->admin())->test(Subscription::class)
            ->set('planId', $plan->id)
            ->set('interval', 'month')
            ->set('couponCode', 'QUARTER')
            ->call('checkCoupon')
            ->assertSet('couponPreview', fn ($v) => $v['valid'] === true && $v['preview']['final_cents'] === 3750);
    }
}
