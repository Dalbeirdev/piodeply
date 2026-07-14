<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Services\BillingService;
use App\Services\SettingsService;
use Livewire\Component;

class BillingSettings extends Component
{
    public bool $enabled = false;

    public string $currency = 'usd';

    public function mount(SettingsService $settings): void
    {
        $this->authorizeManage();
        $this->enabled = (bool) $settings->get('billing.enabled', '0');
        $this->currency = (string) $settings->get('billing.currency', 'usd');
    }

    public function save(SettingsService $settings): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'enabled'  => ['boolean'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $settings->set('billing.enabled', $validated['enabled'] ? '1' : '0');
        $settings->set('billing.currency', strtolower($validated['currency']));

        activity('settings')->causedBy(auth()->user())->log('billing_settings_saved');
        session()->flash('status', 'Billing settings saved.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);
    }

    public function render(BillingService $billing)
    {
        $this->authorizeManage();

        return view('livewire.admin.billing-settings', [
            'hasKeys'    => ! empty(config('services.stripe.secret')) && ! empty(config('services.stripe.key')),
            'isLive'     => $billing->isLive(),
            'configured' => $billing->isConfigured(),
            'tiers'      => BillingService::TIERS,
            'payments'   => \App\Models\Payment::latest()->limit(10)->get(),
        ])->layout('layouts.app');
    }
}
