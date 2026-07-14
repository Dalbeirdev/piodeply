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

    /** Recurring plans surfaced on the pricing page (monthly, per endpoint). */
    public const PLANS = [
        'starter' => ['name' => 'Starter', 'unit_amount' => 200, 'min' => 1],   // $2.00 / endpoint
        'growth'  => ['name' => 'Growth',  'unit_amount' => 150, 'min' => 1],   // $1.50 / endpoint
    ];

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /** Is Stripe usable — enabled by the operator AND keyed in .env? */
    public function isConfigured(): bool
    {
        return (bool) $this->settings->get('billing.enabled', '0')
            && ! empty(config('services.stripe.secret'))
            && ! empty(config('services.stripe.key'));
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
     * Create a Stripe Checkout Session for a subscription and return its
     * hosted URL. Quantity is the number of endpoints.
     */
    public function createCheckout(string $plan, int $quantity, string $successUrl, string $cancelUrl): ?string
    {
        if (! $this->isConfigured() || ! isset(self::PLANS[$plan])) {
            return null;
        }

        $config = self::PLANS[$plan];
        $quantity = max($config['min'], min(100000, $quantity));

        $response = Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->post(self::API . '/checkout/sessions', [
                'mode'        => 'subscription',
                'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $cancelUrl,
                'line_items'  => [[
                    'quantity'   => $quantity,
                    'price_data' => [
                        'currency'     => $this->currency(),
                        'unit_amount'  => $config['unit_amount'],
                        'recurring'    => ['interval' => 'month'],
                        'product_data' => ['name' => "PioDeploy {$config['name']} — per endpoint"],
                    ],
                ]],
                'metadata' => ['plan' => $plan, 'quantity' => $quantity],
            ]);

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning('Stripe checkout failed: ' . $response->body());

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
