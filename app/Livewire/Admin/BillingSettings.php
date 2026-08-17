<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Services\BillingService;
use App\Services\SettingsService;
use App\Services\StripeSettingsService;
use Livewire\Component;

class BillingSettings extends Component
{
    public bool $enabled = false;

    public string $currency = 'usd';

    public string $publishableKey = '';

    /** Write-only: the stored secrets are never sent back to the browser. */
    public string $secretKey = '';

    public string $webhookSecret = '';

    /** Days a client may stay past-due before dunning suspends them. */
    public int $clientGraceDays = 14;

    /** Free days a new subscription gets before the first charge. */
    public int $trialDays = 14;

    /**
     * The currencies offered in the dropdown. Stripe supports 135+, so this
     * is a shortlist for convenience, not a limit — whatever is already
     * stored is added to it in currencyOptions() so an operator on an
     * unlisted currency can still save the form without silently losing it.
     */
    public const COMMON_CURRENCIES = [
        'usd' => 'US Dollar',
        'eur' => 'Euro',
        'gbp' => 'British Pound',
        'cad' => 'Canadian Dollar',
        'aud' => 'Australian Dollar',
        'nzd' => 'New Zealand Dollar',
        'inr' => 'Indian Rupee',
        'sgd' => 'Singapore Dollar',
        'aed' => 'UAE Dirham',
        'chf' => 'Swiss Franc',
        'sek' => 'Swedish Krona',
        'zar' => 'South African Rand',
        'jpy' => 'Japanese Yen',
        'brl' => 'Brazilian Real',
    ];

    /**
     * Result of the on-demand endpoint check: null until asked, because it
     * costs a Stripe round trip and this page should render without one.
     *
     * @var array{ok: bool, rows: list<array{url: string, registered: bool, events: string}>, error: ?string}|null
     */
    public ?array $endpointCheck = null;

    /**
     * Are Stripe's events actually arriving?
     *
     * Nothing here talks to Stripe: it reports what reached us. A webhook
     * that silently stopped delivering cost this install four weeks of
     * subscriptions and invoices with every page still looking healthy, so
     * "when did we last hear anything" earns a place on screen.
     *
     * @return array{state: string, headline: string, detail: string, last: ?\App\Models\WebhookEvent, failures: int}
     */
    private function webhookHealth(): array
    {
        $last = \App\Models\WebhookEvent::latest('created_at')->first();
        $failures = \App\Models\WebhookEvent::where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($last === null) {
            return [
                'state'    => 'bad',
                'headline' => 'No events ever received',
                'detail'   => 'Stripe has never successfully reached this site. Check the endpoints below.',
                'last'     => null,
                'failures' => 0,
            ];
        }

        $days = $last->created_at->diffInDays(now());

        if ($days >= 7) {
            return [
                'state'    => 'warn',
                'headline' => 'Nothing received for '.$last->created_at->diffForHumans(null, true),
                'detail'   => 'Quiet is normal on a small account, but this is also what a broken endpoint or a stale signing secret looks like.',
                'last'     => $last,
                'failures' => $failures,
            ];
        }

        if ($failures > 0) {
            return [
                'state'    => 'warn',
                'headline' => 'Receiving events, but '.$failures.' failed this week',
                'detail'   => 'Stripe retries failures. Check the webhook log for the error.',
                'last'     => $last,
                'failures' => $failures,
            ];
        }

        return [
            'state'    => 'ok',
            'headline' => 'Events arriving normally',
            'detail'   => 'Last received '.$last->created_at->diffForHumans().'.',
            'last'     => $last,
            'failures' => 0,
        ];
    }

    /**
     * Ask Stripe which endpoints it actually has. This is the check that
     * would have caught the real fault: the subscription endpoint was simply
     * never registered, so nothing was ever sent to it.
     */
    public function checkEndpoints(): void
    {
        $this->authorizeManage();

        $expected = [
            url('/stripe/webhook')  => 'subscriptions & invoices',
            url('/billing/webhook') => 'signup checkout',
        ];

        try {
            $registered = collect(
                \Laravel\Cashier\Cashier::stripe()->webhookEndpoints->all(['limit' => 50])->data
            )->keyBy('url');

            $rows = [];
            foreach ($expected as $url => $purpose) {
                $rows[] = [
                    'url'        => $url,
                    'registered' => $registered->has($url) && $registered[$url]->status === 'enabled',
                    'events'     => $purpose,
                ];
            }

            $this->endpointCheck = [
                'ok'    => collect($rows)->every(fn ($r) => $r['registered']),
                'rows'  => $rows,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->endpointCheck = ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, string> */
    private function currencyOptions(): array
    {
        $options = self::COMMON_CURRENCIES;
        $current = strtolower(trim($this->currency));

        if ($current !== '' && ! isset($options[$current])) {
            $options = [$current => strtoupper($current)] + $options;
        }

        return $options;
    }

    public function mount(SettingsService $settings, StripeSettingsService $stripe): void
    {
        $this->authorizeManage();
        $this->enabled = (bool) $settings->get('billing.enabled', '0');
        $this->currency = (string) $settings->get('billing.currency', 'usd');
        $this->publishableKey = (string) $stripe->publishableKey();
        $this->clientGraceDays = (int) $settings->get('billing.client_grace_days', '14');
        $this->trialDays = $settings->trialDays();
    }

    public function save(SettingsService $settings, StripeSettingsService $stripe): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'enabled'        => ['boolean'],
            'currency'       => ['required', 'string', 'size:3'],
            // Publishable keys are pk_test_/pk_live_; blank is allowed (clears).
            'publishableKey' => ['nullable', 'string', 'starts_with:pk_test_,pk_live_', 'max:255'],
            // Secrets are write-only; blank means "leave the stored one".
            'secretKey'      => ['nullable', 'string', 'starts_with:sk_test_,sk_live_,rk_test_,rk_live_', 'max:255'],
            'webhookSecret'  => ['nullable', 'string', 'starts_with:whsec_', 'max:255'],
            'clientGraceDays' => ['required', 'integer', 'between:3,60'],
            // 0 = charge on signup. Stripe refuses a negative trial, so the
            // floor is enforced here rather than in front of a buyer.
            'trialDays'       => ['required', 'integer', 'between:0,365'],
        ]);

        $settings->set('billing.enabled', $validated['enabled'] ? '1' : '0');
        $settings->set('billing.client_grace_days', (string) $validated['clientGraceDays']);
        $settings->set('billing.trial_days', (string) $validated['trialDays']);

        $stripe->save(
            publishableKey: $validated['publishableKey'] ?: null,
            currency: $validated['currency'],
            secret: $validated['secretKey'] ?: null,
            webhookSecret: $validated['webhookSecret'] ?: null,
        );

        // Never keep the secret in the component state / DOM after saving.
        $this->secretKey = '';
        $this->webhookSecret = '';

        activity('settings')->causedBy(auth()->user())->log('billing_settings_saved');
        session()->flash('status', 'Billing settings saved.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);
    }

    public function render(BillingService $billing, StripeSettingsService $stripe)
    {
        $this->authorizeManage();

        return view('livewire.admin.billing-settings', [
            'hasKeys'          => ! empty(config('services.stripe.secret')) && ! empty(config('services.stripe.key')),
            'isLive'           => $billing->isLive(),
            'configured'       => $billing->isConfigured(),
            'stripeConfigured' => $stripe->configured(),
            'hasSecret'        => $stripe->hasSecret(),
            'hasWebhookSecret' => $stripe->hasWebhookSecret(),
            'tiers'            => BillingService::TIERS,
            'currencies'       => $this->currencyOptions(),
            'health'           => $this->webhookHealth(),
            'payments'         => \App\Models\Payment::latest()->limit(10)->get(),
        ])->layout('layouts.app');
    }
}
