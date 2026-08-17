<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Stripe keys, editable in the portal rather than over SSH. They live in the
 * settings table and are pushed into the Stripe/Cashier config at boot, so
 * turning on billing is a form — not an .env edit, a config:cache and a
 * redeploy.
 *
 * The secret and webhook signing secret are encrypted at rest and never sent
 * back to the browser: the form takes a new value or leaves the stored one
 * alone (an empty field means "unchanged", not "clear it"). The publishable
 * key is not a secret and is shown normally.
 *
 * .env still wins when nothing is saved here, so an install already keyed
 * through the environment keeps working.
 */
class StripeSettingsService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function publishableKey(): ?string
    {
        return $this->plain('billing.stripe_pk');
    }

    public function currency(): string
    {
        return strtolower((string) ($this->settings->get('billing.currency') ?: 'usd'));
    }

    /** Configured here at all? If not, .env / config is left in charge. */
    public function configured(): bool
    {
        return $this->publishableKey() !== null && $this->secret() !== null;
    }

    public function hasSecret(): bool
    {
        return trim((string) $this->settings->get('billing.stripe_sk')) !== '';
    }

    public function hasWebhookSecret(): bool
    {
        return trim((string) $this->settings->get('billing.stripe_whsec')) !== '';
    }

    /**
     * @param  string|null  $secret        Null/blank leaves the stored one untouched.
     * @param  string|null  $webhookSecret Null/blank leaves the stored one untouched.
     */
    public function save(?string $publishableKey, string $currency, ?string $secret = null, ?string $webhookSecret = null): void
    {
        $this->settings->set('billing.stripe_pk', trim((string) $publishableKey));
        $this->settings->set('billing.currency', strtolower(trim($currency) ?: 'usd'));

        if ($secret !== null && trim($secret) !== '') {
            $this->settings->set('billing.stripe_sk', Crypt::encryptString(trim($secret)));
        }
        if ($webhookSecret !== null && trim($webhookSecret) !== '') {
            $this->settings->set('billing.stripe_whsec', Crypt::encryptString(trim($webhookSecret)));
        }
    }

    public function secret(): ?string
    {
        return $this->decrypt('billing.stripe_sk');
    }

    public function webhookSecret(): ?string
    {
        return $this->decrypt('billing.stripe_whsec');
    }

    /**
     * The signing secret for the subscription endpoint (/stripe/webhook).
     *
     * Stripe issues a separate secret per endpoint and never reveals it
     * again, so the two endpoints this app exposes — the legacy checkout one
     * and the subscription one — cannot share a secret. Keeping both means a
     * signature check can succeed for either, instead of the app silently
     * rejecting half its events, which is exactly how weeks of subscriptions
     * and invoices went unrecorded.
     */
    public function subscriptionWebhookSecret(): ?string
    {
        return $this->decrypt('billing.stripe_whsec_subs');
    }

    /** Stored by the endpoint registration, not typed by an operator. */
    public function saveSubscriptionWebhookSecret(string $secret): void
    {
        $this->settings->set('billing.stripe_whsec_subs', Crypt::encryptString(trim($secret)));
    }

    /**
     * Push saved keys over the config for this request. Called at boot; does
     * nothing when nothing is saved, so .env still applies.
     */
    public function apply(): void
    {
        if (! $this->configured()) {
            return;
        }

        $pk = $this->publishableKey();
        $sk = $this->secret();
        $whsec = $this->webhookSecret();
        $currency = $this->currency();

        config([
            'services.stripe.key'            => $pk,
            'services.stripe.secret'         => $sk,
            'services.stripe.webhook_secret' => $whsec,
            'services.stripe.webhook_secret_subs' => $this->subscriptionWebhookSecret(),
            'cashier.key'                    => $pk,
            'cashier.secret'                 => $sk,
            'cashier.webhook.secret'         => $whsec,
            'cashier.currency'               => $currency,
        ]);
    }

    private function plain(string $key): ?string
    {
        $value = trim((string) $this->settings->get($key));

        return $value === '' ? null : $value;
    }

    private function decrypt(string $key): ?string
    {
        $stored = (string) $this->settings->get($key);
        if (trim($stored) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable $e) {
            // A rotated APP_KEY makes every stored secret unreadable. Say so
            // rather than authenticate with gibberish.
            Log::warning("Stored Stripe secret [{$key}] could not be decrypted — has APP_KEY changed?");

            return null;
        }
    }
}
