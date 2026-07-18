<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * Read/manage side of the customer billing portal — invoices, the upcoming
 * charge, and payment methods — on top of Cashier. Every method is guarded by
 * `stripeReady()`: with no keys or no Stripe customer it returns an empty
 * result instead of calling Stripe, so the portal renders (and tests run)
 * without a network.
 */
class BillingPortalService
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /** Keys present AND this account is a Stripe customer. */
    public function stripeReady(Account $account): bool
    {
        return ! empty(config('cashier.key'))
            && ! empty(config('cashier.secret'))
            && $account->hasStripeId();
    }

    /** Past invoices, newest first (empty when Stripe is not ready). */
    public function invoices(Account $account): Collection
    {
        if (! $this->stripeReady($account)) {
            return collect();
        }

        return collect($account->invoices());
    }

    /** A preview of the next charge, or null. */
    public function upcomingInvoice(Account $account)
    {
        if (! $this->stripeReady($account)) {
            return null;
        }

        return $account->upcomingInvoice() ?: null;
    }

    /** @return Collection cards on file (empty when Stripe is not ready) */
    public function paymentMethods(Account $account): Collection
    {
        if (! $this->stripeReady($account)) {
            return collect();
        }

        return collect($account->paymentMethods());
    }

    public function defaultPaymentMethod(Account $account)
    {
        if (! $this->stripeReady($account)) {
            return null;
        }

        return $account->defaultPaymentMethod();
    }

    /**
     * Attach a verified card and make it the default. Prepaid cards are
     * rejected here too (same fake-account defence as the trial signup).
     */
    public function updateCard(Account $account, string $paymentMethodId): void
    {
        $this->subscriptions->assertCardAcceptable($paymentMethodId);
        $account->updateDefaultPaymentMethod($paymentMethodId);
    }

    public function setDefaultCard(Account $account, string $paymentMethodId): void
    {
        $account->updateDefaultPaymentMethod($paymentMethodId);
    }

    /** Remove a card; the default cannot be removed while it is in use. */
    public function removeCard(Account $account, string $paymentMethodId): void
    {
        $default = $account->defaultPaymentMethod();
        if ($default && $default->id === $paymentMethodId) {
            throw new \RuntimeException('You cannot remove the default card. Set another card as default first.');
        }

        $account->deletePaymentMethod($paymentMethodId);
    }

    /**
     * Stripe's hosted PDF URL for an invoice, or null if it isn't this
     * customer's / Stripe is not ready. We redirect to Stripe's own PDF rather
     * than render one locally (that needs the optional dompdf package, and
     * Stripe's is the authoritative document). The caller turns null into 404.
     */
    public function invoicePdfUrl(Account $account, string $invoiceId): ?string
    {
        if (! $this->stripeReady($account)) {
            return null;
        }

        // findInvoice returns null for an id that isn't this customer's.
        $invoice = $account->findInvoice($invoiceId);
        if ($invoice === null) {
            return null;
        }

        return $invoice->asStripeInvoice()->invoice_pdf ?: null;
    }
}
