<?php

namespace App\Services;

use App\Models\Account;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;

/**
 * Turns a verified Stripe event into local state changes. Everything here
 * works from the event payload alone — no calls back to Stripe — so the whole
 * webhook path is testable offline and cannot hang on the network.
 */
class WebhookService
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /**
     * Dispatch one event. Returns the outcome recorded on the webhook log:
     * 'processed' when we acted, 'skipped' when the event is not one we model.
     */
    public function handle(array $event): string
    {
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        return match ($type) {
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->syncSubscription($object, deleted: $type === 'customer.subscription.deleted'),
            'invoice.payment_failed'        => $this->paymentFailed($object),
            'invoice.paid'                  => $this->paymentSucceeded($object),
            'charge.refunded'               => 'processed', // logged for the dashboard; no state change
            'checkout.session.completed',
            'payment_intent.succeeded',
            'payment_intent.payment_failed' => 'processed', // acknowledged; our flow acts on the events above
            default                         => 'skipped',
        };
    }

    /** Mirror a Stripe subscription object onto the local Cashier row + account status. */
    private function syncSubscription(array $object, bool $deleted): string
    {
        $subscription = Subscription::where('stripe_id', $object['id'] ?? '')->first();
        if ($subscription === null) {
            return 'skipped'; // a subscription we don't track (belongs to no local account)
        }

        $subscription->stripe_status = $deleted ? 'canceled' : ($object['status'] ?? $subscription->stripe_status);
        $subscription->stripe_price = $object['items']['data'][0]['price']['id'] ?? $subscription->stripe_price;
        $subscription->quantity = $object['quantity'] ?? $subscription->quantity;
        $subscription->trial_ends_at = ! empty($object['trial_end'])
            ? Carbon::createFromTimestamp($object['trial_end'])
            : null;

        if ($deleted) {
            $subscription->ends_at = $subscription->ends_at
                ?? Carbon::createFromTimestamp($object['ended_at'] ?? $object['current_period_end'] ?? time());
        } elseif (! empty($object['cancel_at_period_end']) && ! empty($object['current_period_end'])) {
            $subscription->ends_at = Carbon::createFromTimestamp($object['current_period_end']);
        } else {
            $subscription->ends_at = null; // active and not scheduled to cancel
        }

        $subscription->save();

        $account = Account::find($subscription->account_id);
        if ($account !== null) {
            $this->subscriptions->syncStatus($account);
        }

        return 'processed';
    }

    /** A renewal charge failed: enter past-due with a grace window, or suspend. */
    private function paymentFailed(array $invoice): string
    {
        $account = $this->accountFor($invoice);
        if ($account === null) {
            return 'skipped';
        }

        $next = $invoice['next_payment_attempt'] ?? null;

        $account->forceFill([
            // No further retry scheduled → dunning is exhausted → suspend.
            'status'        => $next ? 'past_due' : 'suspended',
            'grace_ends_at' => $next ? Carbon::createFromTimestamp($next) : null,
        ])->save();

        $account->billingContact()?->notify(new PaymentFailedNotification($account, $next ? Carbon::createFromTimestamp($next) : null));

        return 'processed';
    }

    /** A charge succeeded: back to normal, grace window cleared. */
    private function paymentSucceeded(array $invoice): string
    {
        $account = $this->accountFor($invoice);
        if ($account === null) {
            return 'skipped';
        }

        $account->forceFill(['grace_ends_at' => null])->save();
        $this->subscriptions->syncStatus($account);

        $account->billingContact()?->notify(new \App\Notifications\PaymentReceiptNotification(
            $account,
            $invoice['amount_paid'] ?? ($invoice['total'] ?? null),
            $invoice['currency'] ?? 'usd',
        ));

        // Record the payment for revenue reporting (idempotent by invoice id).
        \App\Models\Payment::updateOrCreate(
            ['reference' => $invoice['id'] ?? ('acct' . $account->id . '-' . now()->timestamp)],
            [
                'provider'       => 'stripe',
                'customer_email' => $account->stripeEmail(),
                'plan'           => $account->plan?->name,
                'amount_total'   => (int) ($invoice['amount_paid'] ?? $invoice['total'] ?? 0),
                'currency'       => $invoice['currency'] ?? 'usd',
                'status'         => 'paid',
            ]
        );

        // Referral commission (no-op unless this account was referred). Base it
        // on the pre-tax subtotal so affiliates aren't paid a cut of the tax.
        app(AffiliateService::class)->accrueCommission(
            $account,
            $invoice['id'] ?? null,
            (int) ($invoice['subtotal'] ?? $invoice['amount_paid'] ?? $invoice['total'] ?? 0),
        );

        return 'processed';
    }

    /**
     * Resolve the account strictly by the invoice's Stripe customer id. No
     * fallback: an event for a customer we don't recognise (a stray/legacy
     * customer under the same Stripe account) must not touch — let alone
     * suspend or credit — the live account.
     */
    private function accountFor(array $invoice): ?Account
    {
        $customer = $invoice['customer'] ?? null;

        return $customer ? Account::where('stripe_id', $customer)->first() : null;
    }
}
