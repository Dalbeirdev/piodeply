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
    public function startTrial(Account $account, Plan $plan, string $interval, string $paymentMethodId): Subscription
    {
        if ($account->subscribed('default')) {
            throw new RuntimeException('This account already has a subscription.');
        }

        $priceId = $plan->stripePriceId($interval);
        if (empty($priceId)) {
            throw new RuntimeException("Plan “{$plan->name}” has no Stripe price for {$interval}ly billing. Run billing:sync-stripe.");
        }

        $this->assertCardAcceptable($paymentMethodId);

        $account->createOrGetStripeCustomer();
        $account->updateDefaultPaymentMethod($paymentMethodId);

        $subscription = $account->newSubscription('default', $priceId)
            ->trialDays(self::TRIAL_DAYS)
            ->create($paymentMethodId);

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

    /**
     * A snapshot for the UI: where the account is in its billing lifecycle.
     *
     * @return array{status: string, on_trial: bool, trial_days_left: ?int,
     *     subscribed: bool, plan: ?Plan, interval: ?string, device_count: int,
     *     device_limit: ?int, over_limit: bool}
     */
    public function state(Account $account): array
    {
        $onTrial = $account->onTrial('default');
        $trialLeft = null;
        if ($onTrial && $account->trial_ends_at) {
            $trialLeft = max(0, (int) ceil(now()->diffInDays($account->trial_ends_at, false)));
        }

        return [
            'status'          => $account->status,
            'on_trial'        => $onTrial,
            'trial_days_left' => $trialLeft,
            'subscribed'      => $account->subscribed('default'),
            'plan'            => $account->plan,
            'interval'        => $account->billing_interval,
            'device_count'    => $account->deviceCount(),
            'device_limit'    => $account->effectiveDeviceLimit(),
            'over_limit'      => $account->isOverDeviceLimit(),
        ];
    }
}
