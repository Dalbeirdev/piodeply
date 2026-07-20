<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout integration — dependency-free (talks to the Stripe REST
 * API directly). Secret keys come from config/.env; operator-tunable bits
 * (currency, per-endpoint price, enabled flag) come from settings.
 *
 * Test mode by default: paste sk_test_/pk_test_ keys and run a full flow
 * with Stripe's test cards before ever touching real money.
 */
class BillingService
{
    private const API = 'https://api.stripe.com/v1';

    /**
     * Graduated per-machine pricing (monthly), deliberately below the
     * common $1.00 / $0.50 / $0.25 market schedule. Each tier: the machine
     * count it runs up to (null = unlimited) and the per-machine price in
     * cents that applies within that band.
     */
    public const TIERS = [
        ['up_to' => 20,   'unit' => 80],   // first 20 machines  @ $0.80
        ['up_to' => 500,  'unit' => 40],   // next 480 (21–500)  @ $0.40
        ['up_to' => null, 'unit' => 20],   // 500+               @ $0.20
    ];

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /** Graduated monthly total, in minor units (cents), for N machines. */
    public function quoteCents(int $machines): int
    {
        $machines = max(1, $machines);
        $total = 0;
        $prev = 0;

        foreach (self::TIERS as $tier) {
            if ($machines <= $prev) {
                break;
            }
            $cap = $tier['up_to'] ?? $machines;
            $count = min($machines, $cap) - $prev;
            $total += $count * $tier['unit'];
            $prev = $cap;
            if ($tier['up_to'] === null) {
                break;
            }
        }

        return $total;
    }

    /**
     * Is Stripe usable? Keys present is the whole answer: saving keys IS
     * the act of turning card payment on. This must not depend on the
     * "legacy checkout" toggle — its label tells operators subscription
     * plans don't need it, and gating the signup wizard on it silently
     * downgraded every paid signup to verify-manually.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.stripe.secret'))
            && ! empty(config('services.stripe.key'));
    }

    /**
     * The old direct per-machine "Subscribe" route on the marketing site —
     * exactly what the admin toggle's label describes, and the only thing
     * it controls.
     */
    public function legacyCheckoutEnabled(): bool
    {
        return (bool) $this->settings->get('billing.enabled', '0')
            && $this->isConfigured();
    }

    public function currency(): string
    {
        return strtolower((string) $this->settings->get('billing.currency', 'usd'));
    }

    public function isLive(): bool
    {
        return str_starts_with((string) config('services.stripe.secret'), 'sk_live_');
    }

    /**
     * Create a Stripe Checkout Session for the graduated monthly total of
     * N machines and return its hosted URL. The computed total is charged
     * as a single monthly line (quantity 1) so no Stripe tiered Price is
     * required.
     */
    public function createCheckout(int $machines, string $successUrl, string $cancelUrl, ?string $customerEmail = null, array $metadata = []): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $machines = max(1, min(100000, $machines));
        $total = $this->quoteCents($machines);

        $response = Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->post(self::API . '/checkout/sessions', array_filter([
                'mode'        => 'subscription',
                'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $cancelUrl,
                // Pre-filling the email ties the Stripe customer to the
                // signup and stops typos diverging the two records.
                'customer_email' => $customerEmail,
                'line_items'  => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => $this->currency(),
                        'unit_amount'  => $total,
                        'recurring'    => ['interval' => 'month'],
                        'product_data' => ['name' => "PioDeploy — {$machines} machines / month"],
                    ],
                ]],
                'metadata' => ['machines' => $machines] + $metadata,
                // Mirror onto the subscription itself: renewal webhooks carry
                // the subscription, not the checkout session, and need to
                // find their way back without a session lookup.
                'subscription_data' => ['metadata' => ['machines' => $machines] + $metadata],
            ]));

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning('Stripe checkout failed: ' . $response->body());

            return null;
        }

        return $response->json('url');
    }

    /**
     * A Stripe Billing Portal session: the hosted page where a customer
     * updates their card, sees invoices, or cancels — all on Stripe's side,
     * so no card data or cancellation logic ever lives here.
     */
    public function createPortalSession(string $customerId, string $returnUrl): ?string
    {
        if (empty(config('services.stripe.secret'))) {
            return null;
        }

        $response = Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->post(self::API . '/billing_portal/sessions', [
                'customer'   => $customerId,
                'return_url' => $returnUrl,
            ]);

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning('Stripe portal session failed: ' . $response->body());

            return null;
        }

        return $response->json('url');
    }

    /**
     * Verify a Stripe webhook signature (HMAC-SHA256 over "t.payload").
     * Constant-time compare; tolerates 5 minutes of clock skew.
     */
    public function verifyWebhook(string $payload, ?string $signatureHeader): bool
    {
        $secret = config('services.stripe.webhook_secret');
        if (empty($secret) || empty($signatureHeader)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[$k][] = $v;
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];
        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }
}
