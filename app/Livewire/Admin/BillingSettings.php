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

    public function mount(SettingsService $settings, StripeSettingsService $stripe): void
    {
        $this->authorizeManage();
        $this->enabled = (bool) $settings->get('billing.enabled', '0');
        $this->currency = (string) $settings->get('billing.currency', 'usd');
        $this->publishableKey = (string) $stripe->publishableKey();
        $this->clientGraceDays = (int) $settings->get('billing.client_grace_days', '14');
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
        ]);

        $settings->set('billing.enabled', $validated['enabled'] ? '1' : '0');
        $settings->set('billing.client_grace_days', (string) $validated['clientGraceDays']);

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
            'payments'         => \App\Models\Payment::latest()->limit(10)->get(),
        ])->layout('layouts.app');
    }
}
