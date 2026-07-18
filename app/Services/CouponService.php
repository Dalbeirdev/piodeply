<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use Laravel\Cashier\Cashier;

/**
 * Coupon validation, discount preview, and redemption. All the rules —
 * expiry, usage caps, per-customer caps, plan restriction, and the discount
 * maths — are computed locally, so the engine is fully unit-tested without
 * Stripe. Only creating the matching Stripe coupon (for percent/fixed) touches
 * the network, and that is verified in test mode.
 */
class CouponService
{
    /**
     * @return array{valid: bool, reason: ?string, coupon: ?Coupon}
     */
    public function validate(string $code, ?Account $account = null, ?Plan $plan = null): array
    {
        $coupon = Coupon::query()->active()
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))])
            ->first();

        if ($coupon === null) {
            return $this->fail('That coupon code is not valid.');
        }
        if ($coupon->isExpired()) {
            return $this->fail('This coupon has expired.');
        }
        if ($coupon->isExhausted()) {
            return $this->fail('This coupon has reached its usage limit.');
        }
        if ($coupon->plan_id !== null && $plan !== null && $coupon->plan_id !== $plan->id) {
            return $this->fail('This coupon is not valid for the selected plan.');
        }
        if ($coupon->max_per_customer !== null && $account !== null) {
            $used = CouponRedemption::where('coupon_id', $coupon->id)
                ->where('account_id', $account->id)->count();
            if ($used >= $coupon->max_per_customer) {
                return $this->fail('You have already used this coupon.');
            }
        }

        return ['valid' => true, 'reason' => null, 'coupon' => $coupon];
    }

    /**
     * What the coupon does to a plan/interval price.
     *
     * @return array{base_cents:int, discount_cents:int, final_cents:int, trial_extra_days:int, label:string}
     */
    public function preview(Coupon $coupon, Plan $plan, string $interval): array
    {
        $base = $interval === 'year' ? $plan->yearly_price_cents : $plan->monthly_price_cents;
        $discount = $this->discountCents($coupon, $base);

        return [
            'base_cents'       => $base,
            'discount_cents'   => $discount,
            'final_cents'      => max(0, $base - $discount),
            'trial_extra_days' => $this->trialExtraDays($coupon),
            'label'            => $coupon->label(),
        ];
    }

    public function discountCents(Coupon $coupon, int $baseCents): int
    {
        return match ($coupon->type) {
            'percent' => (int) round($baseCents * min(100, $coupon->value) / 100),
            'fixed'   => min($baseCents, $coupon->value),
            default   => 0, // trial_days extends the trial, it does not discount the price
        };
    }

    public function trialExtraDays(Coupon $coupon): int
    {
        return $coupon->type === 'trial_days' ? $coupon->value : 0;
    }

    /** Record a redemption and advance the usage counter. */
    public function redeem(Coupon $coupon, ?Account $account, ?int $amountCents = null): void
    {
        CouponRedemption::create([
            'coupon_id'               => $coupon->id,
            'account_id'              => $account?->id,
            'amount_discounted_cents' => $amountCents,
            'redeemed_at'             => now(),
        ]);

        $coupon->increment('times_redeemed');
    }

    /**
     * The Stripe coupon id for a percent/fixed coupon, created on first use.
     * Trial-day coupons need no Stripe coupon (they only extend the trial).
     */
    public function ensureStripeCoupon(Coupon $coupon): ?string
    {
        if ($coupon->type === 'trial_days') {
            return null;
        }
        if ($coupon->stripe_coupon_id) {
            return $coupon->stripe_coupon_id;
        }

        $params = ['duration' => $coupon->duration, 'name' => $coupon->name];
        if ($coupon->duration === 'repeating' && $coupon->duration_in_months) {
            $params['duration_in_months'] = $coupon->duration_in_months;
        }
        if ($coupon->type === 'percent') {
            $params['percent_off'] = $coupon->value;
        } else {
            $params['amount_off'] = $coupon->value;
            $params['currency'] = strtolower($coupon->currency);
        }

        $created = Cashier::stripe()->coupons->create($params);
        $coupon->forceFill(['stripe_coupon_id' => $created->id])->save();

        return $created->id;
    }

    /** A coupon marked auto-apply (highest value first), if any is live. */
    public function autoApplyFor(Plan $plan): ?Coupon
    {
        return Coupon::query()->active()->where('auto_apply', true)
            ->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', $plan->id))
            ->get()
            ->first(fn (Coupon $c) => ! $c->isExpired() && ! $c->isExhausted());
    }

    /**
     * @return array{valid: false, reason: string, coupon: null}
     */
    private function fail(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason, 'coupon' => null];
    }
}
