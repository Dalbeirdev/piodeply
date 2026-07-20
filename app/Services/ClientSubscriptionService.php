<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Signup;
use Illuminate\Support\Facades\Log;

/**
 * Keeps each client's subscription columns in step with Stripe. Stripe is
 * the system of record — it charges the card every month on its own; this
 * service only mirrors what its webhooks report, so the portal can answer
 * "is this client paid up, and until when?" without calling Stripe.
 *
 * Events can arrive in any order and more than once (Stripe retries), so
 * every handler is idempotent and resolves its target defensively.
 */
class ClientSubscriptionService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * checkout.session.completed — the wizard's checkout finished. Records
     * the customer + subscription on the signup (the client may not exist
     * yet; approval copies them over) and on the client when it does.
     */
    public function recordCheckout(array $session): void
    {
        $signupId = (int) ($session['metadata']['signup_id'] ?? 0);
        if ($signupId === 0) {
            return; // a checkout born elsewhere (legacy pricing route)
        }

        $signup = Signup::find($signupId);
        if ($signup === null) {
            return;
        }

        $signup->forceFill(array_filter([
            'stripe_customer_id'     => $session['customer'] ?? null,
            'stripe_subscription_id' => $session['subscription'] ?? null,
        ]))->save();

        if ($signup->client_id !== null) {
            $this->syncClientFromSignup($signup, status: 'active');
        }
    }

    /** invoice.paid — a renewal (or the first charge) went through. */
    public function invoicePaid(array $invoice): void
    {
        $client = $this->resolveClient($invoice['subscription'] ?? null);
        if ($client === null) {
            return;
        }

        $periodEnd = $invoice['lines']['data'][0]['period']['end'] ?? $invoice['period_end'] ?? null;

        $client->forceFill(array_filter([
            'subscription_status'     => 'active',
            'subscription_cents'      => $invoice['amount_paid'] ?? null,
            'subscription_period_end' => $periodEnd !== null ? date('Y-m-d H:i:s', (int) $periodEnd) : null,
        ]))->saveQuietly();
    }

    /**
     * invoice.payment_failed — the card was declined. Stripe retries on its
     * own schedule; our job is to make the failure visible, not to punish:
     * the fleet keeps working while the operator chases the payment.
     */
    public function invoiceFailed(array $invoice): void
    {
        $client = $this->resolveClient($invoice['subscription'] ?? null);
        if ($client === null) {
            return;
        }

        $client->forceFill(['subscription_status' => 'past_due'])->saveQuietly();

        $this->notifications->notify(
            'billing.payment_failed',
            "Subscription payment failed for {$client->company_name}",
            [
                'client'  => $client->company_name,
                'email'   => $client->billing_email ?? $client->email,
                'amount'  => isset($invoice['amount_due']) ? number_format($invoice['amount_due'] / 100, 2) : null,
                'attempt' => $invoice['attempt_count'] ?? null,
            ]
        );
    }

    /** customer.subscription.updated — status or price changed at Stripe. */
    public function subscriptionUpdated(array $subscription): void
    {
        $client = $this->resolveClient($subscription['id'] ?? null);
        if ($client === null) {
            return;
        }

        $client->forceFill(array_filter([
            'subscription_status'     => $subscription['status'] ?? null,
            'subscription_cents'      => $subscription['items']['data'][0]['price']['unit_amount'] ?? null,
            'subscription_period_end' => isset($subscription['current_period_end'])
                ? date('Y-m-d H:i:s', (int) $subscription['current_period_end'])
                : null,
        ]))->saveQuietly();
    }

    /** customer.subscription.deleted — cancelled, by them or by dunning. */
    public function subscriptionDeleted(array $subscription): void
    {
        $client = $this->resolveClient($subscription['id'] ?? null);
        if ($client === null) {
            return;
        }

        $client->forceFill(['subscription_status' => 'canceled'])->saveQuietly();

        $this->notifications->notify(
            'billing.subscription_canceled',
            "Subscription canceled: {$client->company_name}",
            ['client' => $client->company_name, 'email' => $client->billing_email ?? $client->email]
        );
    }

    /** Approval-time copy: the signup's Stripe identity becomes the client's. */
    public function syncClientFromSignup(Signup $signup, ?string $status = null): void
    {
        $client = $signup->client;
        if ($client === null) {
            return;
        }

        $client->forceFill(array_filter([
            'stripe_customer_id'     => $signup->stripe_customer_id,
            'stripe_subscription_id' => $signup->stripe_subscription_id,
            'subscription_status'    => $status ?? ($signup->paid_at !== null ? 'active' : null),
            'subscription_machines'  => $signup->machines,
            'subscription_cents'     => $signup->monthly_cents,
        ]))->saveQuietly();
    }

    private function resolveClient(?string $subscriptionId): ?Client
    {
        if (empty($subscriptionId)) {
            return null;
        }

        $client = Client::where('stripe_subscription_id', $subscriptionId)->first();
        if ($client !== null) {
            return $client;
        }

        // The event may outrun approval: the subscription is known only to
        // the signup so far. If that signup has since gained a client, heal
        // the link now; otherwise there is nothing to update yet.
        $signup = Signup::where('stripe_subscription_id', $subscriptionId)->whereNotNull('client_id')->first();
        if ($signup !== null) {
            $this->syncClientFromSignup($signup);

            return $signup->client;
        }

        Log::info("Stripe subscription {$subscriptionId} has no client yet; event deferred to approval-time sync.");

        return null;
    }
}
