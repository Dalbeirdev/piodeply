<?php

namespace App\Livewire\Billing;

use App\Models\Account;
use App\Services\BillingPortalService;
use Livewire\Component;

/**
 * The customer billing portal: invoices + PDF download, the upcoming charge,
 * and payment-method management. Card entry uses the same Stripe.js SetupIntent
 * flow as signup, so raw card data never reaches the server.
 */
class Portal extends Component
{
    public Account $account;

    public ?string $stripeClientSecret = null;

    /** Set by Stripe.js after a card is verified. */
    public ?string $paymentMethod = null;

    public ?string $errorMessage = null;

    public function mount(BillingPortalService $portal): void
    {
        $this->authorize('manage-billing');
        $this->account = Account::current();

        if ($portal->stripeReady($this->account)) {
            $this->stripeClientSecret = $this->account->createSetupIntent()->client_secret;
        }
    }

    public function billingConfigured(): bool
    {
        return ! empty(config('cashier.key')) && ! empty(config('cashier.secret'));
    }

    private function run(callable $action, string $success): void
    {
        $this->authorize('manage-billing');
        $this->errorMessage = null;

        try {
            $action();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        session()->flash('status', $success);
        $this->redirectRoute('billing.invoices', navigate: true);
    }

    public function saveCard(BillingPortalService $portal): void
    {
        $this->validate(['paymentMethod' => ['required', 'string']]);
        $this->run(
            fn () => $portal->updateCard($this->account, $this->paymentMethod),
            'Card updated — future charges will use this card.'
        );
    }

    public function setDefault(string $paymentMethodId, BillingPortalService $portal): void
    {
        $this->run(fn () => $portal->setDefaultCard($this->account, $paymentMethodId), 'Default card updated.');
    }

    public function removeCard(string $paymentMethodId, BillingPortalService $portal): void
    {
        $this->run(fn () => $portal->removeCard($this->account, $paymentMethodId), 'Card removed.');
    }

    public function render(BillingPortalService $portal)
    {
        return view('livewire.billing.portal', [
            'invoices'   => $portal->invoices($this->account),
            'upcoming'   => $portal->upcomingInvoice($this->account),
            'cards'      => $portal->paymentMethods($this->account),
            'defaultPm'  => $portal->defaultPaymentMethod($this->account),
        ])->layout('layouts.app');
    }
}
