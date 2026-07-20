<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Services\BillingService;
use Livewire\Component;

/**
 * The signed-in client's view of their own subscription: what they pay,
 * whether the last charge worked, and when it renews. Everything that
 * touches the card — updating it, viewing invoices, cancelling — happens
 * on Stripe's hosted Billing Portal, so no payment machinery lives here.
 *
 * Tenant-only by construction: the client is resolved from the signed-in
 * user's binding, never from input, so nobody can read another tenant's
 * billing by changing a parameter.
 */
class TenantBilling extends Component
{
    public function mount(): void
    {
        abort_if(auth()->user()->tenantClientId() === null, 404);
    }

    private function client(): Client
    {
        return Client::findOrFail(auth()->user()->tenantClientId());
    }

    /** Hands off to Stripe's hosted portal for card / invoices / cancel. */
    public function openPortal(BillingService $billing)
    {
        $client = $this->client();

        if ($client->stripe_customer_id === null) {
            session()->flash('error', 'No online payment method on file — your subscription is invoiced. Contact us to change billing.');

            return null;
        }

        $url = $billing->createPortalSession($client->stripe_customer_id, route('tenant.billing'));

        if ($url === null) {
            session()->flash('error', 'The billing portal is temporarily unavailable. Please try again shortly.');

            return null;
        }

        return redirect()->away($url);
    }

    public function render()
    {
        $client = $this->client();

        return view('livewire.clients.tenant-billing', [
            'client'       => $client,
            'machineCount' => \App\Models\Computer::whereHas('project', fn ($q) => $q->where('client_id', $client->id))->count(),
        ])->layout('layouts.app');
    }
}
