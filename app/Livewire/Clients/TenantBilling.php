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
    /** Machine count for the self-serve resize form. */
    public int $resizeMachines = 0;

    public function mount(): void
    {
        abort_if(auth()->user()->tenantClientId() === null, 404);
        $this->resizeMachines = $this->client()->subscription_machines ?? 0;
    }

    private function client(): Client
    {
        return Client::findOrFail(auth()->user()->tenantClientId());
    }

    /**
     * Billing belongs to whoever owns the account — not to the Manager who
     * runs the fleet, and certainly not to a technician. Same line the Team
     * page draws.
     */
    private function authorizeOwner(): void
    {
        abort_unless(auth()->user()->isClientOwner(), 403);
    }

    /**
     * Self-serve plan resize: new machine count at the graduated price,
     * prorated by Stripe on the next invoice. No phone call, no ticket.
     */
    public function resize(BillingService $billing): void
    {
        $this->authorizeOwner();

        $this->validate(['resizeMachines' => ['required', 'integer', 'between:1,100000']]);

        $client = $this->client();

        if ($client->stripe_subscription_id === null
            || in_array($client->subscription_status, ['canceled', null], true)) {
            session()->flash('error', 'No active online subscription to resize — contact us to change an invoiced plan.');

            return;
        }

        if ($this->resizeMachines === $client->subscription_machines) {
            session()->flash('error', 'That is already your current plan size.');

            return;
        }

        if (! $billing->resizeSubscription($client->stripe_subscription_id, $this->resizeMachines)) {
            session()->flash('error', 'The plan change could not be applied right now. Please try again shortly.');

            return;
        }

        // Reflect immediately; the subscription.updated webhook will confirm
        // with Stripe's own numbers moments later.
        $client->forceFill([
            'subscription_machines' => $this->resizeMachines,
            'subscription_cents'    => $billing->quoteCents($this->resizeMachines),
        ])->saveQuietly();

        activity('billing')
            ->causedBy(auth()->user())
            ->performedOn($client)
            ->withProperties(['machines' => $this->resizeMachines])
            ->log('subscription_resized');

        session()->flash('status', 'Plan updated to '.number_format($this->resizeMachines).' machines — the difference is prorated on your next invoice.');
    }

    /** Hands off to Stripe's hosted portal for card / invoices / cancel. */
    public function openPortal(BillingService $billing)
    {
        $this->authorizeOwner();

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
            'resizeQuote'  => app(BillingService::class)->quoteCents(max(1, $this->resizeMachines)),
            'invoices'     => $client->stripe_customer_id
                ? app(BillingService::class)->listInvoices($client->stripe_customer_id)
                : [],
            'upcoming'     => $client->stripe_customer_id
                ? app(BillingService::class)->upcomingInvoice($client->stripe_customer_id)
                : null,
            'isOwner'      => auth()->user()->isClientOwner(),
        ])->layout('layouts.app');
    }
}
