<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * The trial the operator is offering, card required: card verified
     * today, $0 charged, first invoice when the trial ends. Stripe cancels
     * the subscription itself if no card can be charged by then. Whatever
     * the pricing page promises has to come from here too, or the buyer is
     * told one number and Stripe is told another.
     */
    public function trialDays(): int
    {
        return $this->settings->trialDays();
    }

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
                'subscription_data' => [
                    'metadata'          => ['machines' => $machines] + $metadata,
                    'trial_period_days' => $this->trialDays(),
                ],
            ]));

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning('Stripe checkout failed: ' . $response->body());

            return null;
        }

        return $response->json('url');
    }

    /**
     * Resize an existing subscription to a new machine count at the
     * graduated price. Stripe prorates the difference on the next invoice
     * (credit when shrinking, charge when growing), so nobody pays twice
     * and nobody rings anyone to change a number.
     */
    public function resizeSubscription(string $subscriptionId, int $machines): bool
    {
        if (empty(config('services.stripe.secret'))) {
            return false;
        }

        $machines = max(1, min(100000, $machines));

        $current = Http::withToken(config('services.stripe.secret'))
            ->get(self::API . "/subscriptions/{$subscriptionId}");

        $itemId = $current->json('items.data.0.id');
        if ($current->failed() || $itemId === null) {
            \Illuminate\Support\Facades\Log::warning("Stripe resize: could not read subscription {$subscriptionId}: " . $current->body());

            return false;
        }

        $response = Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->post(self::API . "/subscriptions/{$subscriptionId}", [
                'items' => [[
                    'id'         => $itemId,
                    'price_data' => [
                        'currency'     => $this->currency(),
                        'unit_amount'  => $this->quoteCents($machines),
                        'recurring'    => ['interval' => 'month'],
                        'product_data' => ['name' => "PioDeploy — {$machines} machines / month"],
                    ],
                ]],
                'proration_behavior' => 'create_prorations',
                'metadata'           => ['machines' => $machines],
            ]);

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning("Stripe resize failed for {$subscriptionId}: " . $response->body());

            return false;
        }

        return true;
    }

    /**
     * Paid/open invoices for a customer, newest first — date, amount,
     * status and Stripe's hosted PDF link. Empty on any failure: billing
     * history is nice-to-have, never a page-breaker.
     */
    public function listInvoices(string $customerId, int $limit = 12): array
    {
        if (empty(config('services.stripe.secret'))) {
            return [];
        }

        $response = Http::withToken(config('services.stripe.secret'))
            ->get(self::API . '/invoices', ['customer' => $customerId, 'limit' => $limit]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('data') ?? [])->map(fn ($inv) => [
            'number' => $inv['number'] ?? ($inv['id'] ?? ''),
            'date'   => isset($inv['created']) ? date('j M Y', (int) $inv['created']) : '',
            'amount' => number_format(($inv['amount_paid'] ?: $inv['amount_due'] ?? 0) / 100, 2),
            'status' => $inv['status'] ?? '',
            'url'    => $inv['hosted_invoice_url'] ?? null,
        ])->all();
    }

    /**
     * The next charge: amount and date, or null when Stripe has none planned.
     *
     * Uses Stripe's Create Preview Invoice API. The older /invoices/upcoming
     * endpoint was retired and now answers 404, which this method quietly
     * turned into "no upcoming payment" — so the customer's billing page
     * simply stopped showing their next charge, with nothing logged. A
     * preview needs the subscription, not just the customer.
     */
    public function upcomingInvoice(string $customerId, ?string $subscriptionId = null): ?array
    {
        if (empty(config('services.stripe.secret')) || $subscriptionId === null) {
            return null;
        }

        $response = Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->post(self::API . '/invoices/create_preview', [
                'customer'     => $customerId,
                'subscription' => $subscriptionId,
            ]);

        if ($response->failed()) {
            // Loud enough to find, quiet enough not to break the page: a
            // preview is a nicety, but a silent failure hid this for weeks.
            Log::warning('Stripe upcoming-invoice preview failed', [
                'customer'     => $customerId,
                'subscription' => $subscriptionId,
                'status'       => $response->status(),
                'error'        => $response->json('error.message'),
            ]);

            return null;
        }

        $when = $response->json('next_payment_attempt') ?? $response->json('period_end');

        return [
            'amount' => number_format(($response->json('amount_due') ?? 0) / 100, 2),
            'date'   => $when ? date('j M Y', (int) $when) : null,
        ];
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
        // Each Stripe endpoint has its own signing secret, so a payload is
        // valid if ANY configured secret verifies it. Checking only one meant
        // every event from the other endpoint was rejected as a forgery.
        $secrets = array_values(array_filter([
            config('services.stripe.webhook_secret'),
            config('services.stripe.webhook_secret_subs'),
        ], fn ($s) => ! empty($s)));

        if ($secrets === [] || empty($signatureHeader)) {
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

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

            foreach ($signatures as $candidate) {
                if (hash_equals($expected, (string) $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }
}
