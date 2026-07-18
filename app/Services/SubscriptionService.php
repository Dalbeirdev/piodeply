<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Plan;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use RuntimeException;

/**
 * Subscription lifecycle on top of Cashier. Phase 2 covers getting a verified
 * card on file and starting the 14-day trial; later phases add swap / cancel /
 * resume / pause. Stripe calls are isolated in thin methods so the pure state
 * transitions stay unit-testable without a network.
 */
class SubscriptionService
{
    public const TRIAL_DAYS = 14;

    /** A SetupIntent so the browser can verify and tokenise a card. */
    public function setupIntent(Account $account)
    {
        return $account->createSetupIntent();
    }

    /** The card's funding type, straight from Stripe ('credit'|'debit'|'prepaid'|'unknown'). */
    public function cardFunding(string $paymentMethodId): string
    {
        $pm = Cashier::stripe()->paymentMethods->retrieve($paymentMethodId, []);

        return $pm->card->funding ?? 'unknown';
    }

    /**
     * Reject prepaid cards — they are the classic fake-account / chargeback
     * vector for a free trial. Stripe reports funding on the payment method.
     */
    public function assertCardAcceptable(string $paymentMethodId): void
    {
        if ($this->cardFunding($paymentMethodId) === 'prepaid') {
            throw new RuntimeException('Prepaid cards are not accepted. Please use a credit or debit card.');
        }
    }

    /**
     * Verify the card, attach it, and open the 14-day trial on the chosen plan.
     * The customer is not charged until the trial ends.
     */
    public function startTrial(Account $account, Plan $plan, string $interval, string $paymentMethodId, ?string $couponCode = null): Subscription
    {
        if ($account->subscribed('default')) {
            throw new RuntimeException('This account already has a subscription.');
        }

        $priceId = $plan->stripePriceId($interval);
        if (empty($priceId)) {
            throw new RuntimeException("Plan “{$plan->name}” has no Stripe price for {$interval}ly billing. Run billing:sync-stripe.");
        }

        $this->assertCardAcceptable($paymentMethodId);

        // Resolve a coupon before we touch Stripe, so an invalid code fails
        // cleanly with no half-built subscription.
        $coupons = app(CouponService::class);
        $coupon = null;
        $extraTrialDays = 0;
        $stripeCoupon = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $result = $coupons->validate($couponCode, $account, $plan);
            if (! $result['valid']) {
                throw new RuntimeException($result['reason']);
            }
            $coupon = $result['coupon'];
            $extraTrialDays = $coupons->trialExtraDays($coupon);
            $stripeCoupon = $coupons->ensureStripeCoupon($coupon); // null for trial-day coupons
        }

        $account->createOrGetStripeCustomer();
        $account->updateDefaultPaymentMethod($paymentMethodId);

        $builder = $account->newSubscription('default', $priceId)
            ->trialDays(self::TRIAL_DAYS + $extraTrialDays);
        if ($stripeCoupon !== null) {
            $builder->withCoupon($stripeCoupon);
        }
        $subscription = $builder->create($paymentMethodId);

        if ($coupon !== null) {
            $discount = $coupons->discountCents($coupon, $plan->{$interval === 'year' ? 'yearly_price_cents' : 'monthly_price_cents'});
            $coupons->redeem($coupon, $account, $discount ?: null);
        }

        $this->applyPlan($account, $plan, $interval, 'trialing');
        $account->forceFill([
            'trial_ends_at'          => $subscription->trial_ends_at,
            'trial_reminder_sent_at' => null,
        ])->save();

        $account->billingContact()?->notify(new \App\Notifications\TrialStartedNotification($account));

        return $subscription;
    }

    /**
     * Copy a plan's terms onto the account. Pure (no Stripe) so it is fully
     * unit-testable. The device limit follows the plan unless an admin has
     * pinned an override (Module 11).
     *
     * @param  string  $status  none|trialing|active|past_due|grace|suspended|canceled
     */
    public function applyPlan(Account $account, ?Plan $plan, ?string $interval, string $status): void
    {
        $account->plan_id = $plan?->id;
        $account->billing_interval = $interval;
        $account->status = $status;

        if (! $account->device_limit_overridden) {
            $account->device_limit = $plan?->device_limit;
        }

        $account->save();
    }

    // ── Lifecycle (Phase 3) ────────────────────────────────────────────

    /**
     * Move to a different plan or interval. Cashier swaps the Stripe price and
     * prorates automatically (a credit/charge on the next invoice). Keeps the
     * trial if one is running.
     */
    public function changePlan(Account $account, Plan $plan, string $interval): void
    {
        $subscription = $account->subscription('default');
        if ($subscription === null) {
            throw new RuntimeException('No active subscription to change.');
        }

        $priceId = $plan->stripePriceId($interval);
        if (empty($priceId)) {
            throw new RuntimeException("Plan “{$plan->name}” has no Stripe price for {$interval}ly billing. Run billing:sync-stripe.");
        }

        $subscription->swap($priceId);
        $this->applyPlan($account, $plan, $interval, $this->deriveStatus($account->refresh()));
    }

    /** Cancel at period end — access continues until the paid period expires. */
    public function cancel(Account $account): void
    {
        $subscription = $account->subscription('default');
        $subscription?->cancel();
        $this->syncStatus($account);

        $account->billingContact()?->notify(
            new \App\Notifications\SubscriptionCancelledNotification($account, $subscription?->fresh()?->ends_at)
        );
    }

    /** Cancel immediately — access ends now. */
    public function cancelNow(Account $account): void
    {
        $account->subscription('default')?->cancelNow();
        $this->syncStatus($account);
    }

    /** Resume a subscription cancelled but still within its grace period. */
    public function resume(Account $account): void
    {
        $subscription = $account->subscription('default');
        if ($subscription === null || ! $subscription->onGracePeriod()) {
            throw new RuntimeException('Nothing to resume — the subscription is not in its grace period.');
        }

        $subscription->resume();
        $this->syncStatus($account);
    }

    /** Pause billing (Stripe pause_collection): the fleet keeps running, no charges. */
    public function pause(Account $account): void
    {
        $subscription = $account->subscription('default');
        if ($subscription === null) {
            throw new RuntimeException('No active subscription to pause.');
        }

        $subscription->updateStripeSubscription(['pause_collection' => ['behavior' => 'void']]);
        $account->forceFill(['paused_at' => now()])->save();
        $this->syncStatus($account);
    }

    public function unpause(Account $account): void
    {
        $subscription = $account->subscription('default');
        if ($subscription !== null) {
            $subscription->updateStripeSubscription(['pause_collection' => '']);
        }

        $account->forceFill(['paused_at' => null])->save();
        $this->syncStatus($account);
    }

    /**
     * The account's billing status derived from its (local) Cashier subscription
     * plus our pause flag. Pure — reads no network — so it is fully testable by
     * building subscription rows directly.
     */
    public function deriveStatus(Account $account): string
    {
        $subscription = $account->subscription('default');

        if ($subscription === null) {
            return 'none';
        }
        if ($account->isPaused()) {
            return 'paused';
        }
        // Dunning exhausted: Stripe marks the subscription unpaid.
        if ($subscription->stripe_status === 'unpaid') {
            return 'suspended';
        }
        if ($subscription->pastDue()) {
            return 'past_due';
        }
        if ($subscription->onTrial()) {
            return 'trialing';
        }
        if ($subscription->onGracePeriod()) {
            return 'grace';   // cancelled, but paid period not over yet
        }
        if ($subscription->ended()) {
            return 'canceled';
        }
        if ($subscription->active()) {
            return 'active';
        }

        return 'none';
    }

    /** Persist the derived status onto the account. */
    public function syncStatus(Account $account): void
    {
        $account->forceFill(['status' => $this->deriveStatus($account)])->save();
    }

    /**
     * A snapshot for the UI: where the account is in its billing lifecycle.
     *
     * @return array{status: string, on_trial: bool, trial_days_left: ?int,
     *     subscribed: bool, plan: ?Plan, interval: ?string, device_count: int,
     *     device_limit: ?int, over_limit: bool}
     */
    public function state(Account $account): array
    {
        $subscription = $account->subscription('default');
        $onTrial = $account->onTrial('default');
        $trialLeft = null;
        if ($onTrial && $account->trial_ends_at) {
            $trialLeft = max(0, (int) ceil(now()->diffInDays($account->trial_ends_at, false)));
        }

        $onGrace = (bool) $subscription?->onGracePeriod();

        return [
            'status'          => $this->deriveStatus($account),
            'on_trial'        => $onTrial,
            'trial_days_left' => $trialLeft,
            'subscribed'      => $account->subscribed('default'),
            'plan'            => $account->plan,
            'interval'        => $account->billing_interval,
            'device_count'    => $account->deviceCount(),
            'device_limit'    => $account->effectiveDeviceLimit(),
            'over_limit'      => $account->isOverDeviceLimit(),
            // Which lifecycle actions apply right now.
            'on_grace'        => $onGrace,
            'paused'          => $account->isPaused(),
            'ends_at'         => $subscription?->ends_at,
            'can_change'      => $subscription !== null && ! $onGrace && ! $account->isPaused(),
            'can_cancel'      => $subscription !== null && $subscription->active() && ! $onGrace,
            'can_resume'      => $onGrace,
            'can_pause'       => $subscription !== null && $subscription->active() && ! $account->isPaused() && ! $onGrace,
        ];
    }
}
