<?php

namespace App\Livewire\Billing;

use App\Models\Account;
use App\Models\Plan;
use App\Services\PricingService;
use App\Services\SubscriptionService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The account owner's subscription screen: pick a plan + interval, add a
 * verified card, and start the 14-day trial. Card data never touches the
 * server — Stripe.js exchanges it for a payment-method id via a SetupIntent,
 * and only that id is posted back.
 */
class Subscription extends Component
{
    public Account $account;

    #[Validate]
    public string $interval = 'month';

    #[Validate]
    public ?int $planId = null;

    /** Set by the Stripe.js callback after the card is verified. */
    public ?string $paymentMethod = null;

    /** SetupIntent secret used by Stripe.js in the browser (safe to expose). */
    public ?string $stripeClientSecret = null;

    public ?string $errorMessage = null;

    public function rules(): array
    {
        return [
            'interval' => ['required', Rule::in(['month', 'year'])],
            'planId'   => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
        ];
    }

    public function mount(): void
    {
        $this->authorize('manage-billing');
        $this->account = Account::current();

        if ($this->planId === null) {
            $recommended = app(PricingService::class)->plans()->firstWhere('is_recommended', true);
            $this->planId = $recommended?->id ?? app(PricingService::class)->plans()->first()?->id;
        }

        // One SetupIntent per page load — reused across re-renders (interval
        // toggle etc.) so we don't create a new intent on every interaction.
        if ($this->billingConfigured() && ! $this->account->subscribed('default')) {
            $this->stripeClientSecret = $this->account->createSetupIntent()->client_secret;
        }
    }

    /** Whether Stripe keys are present — the form only works when they are. */
    public function billingConfigured(): bool
    {
        return ! empty(config('cashier.key')) && ! empty(config('cashier.secret'));
    }

    /**
     * Called from the browser once Stripe.js has verified the card and handed
     * back a payment-method id. Opens the trial.
     */
    public function startTrial(SubscriptionService $subscriptions): void
    {
        $this->authorize('manage-billing');
        $this->errorMessage = null;
        $this->validate();
        $this->validate(['paymentMethod' => ['required', 'string']]);

        $plan = Plan::findOrFail($this->planId);

        try {
            $subscriptions->startTrial($this->account, $plan, $this->interval, $this->paymentMethod);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->account->refresh();
        session()->flash('status', "Your 14-day trial of the {$plan->name} plan has started.");
        $this->redirectRoute('billing.subscription', navigate: true);
    }

    // ── Lifecycle actions (Phase 3) ────────────────────────────────────

    /** Run a billing action, mapping any failure to a friendly message. */
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

        $this->account->refresh();
        session()->flash('status', $success);
        $this->redirectRoute('billing.subscription', navigate: true);
    }

    public function changePlan(SubscriptionService $subscriptions): void
    {
        $this->validate();
        $plan = Plan::findOrFail($this->planId);
        $this->run(
            fn () => $subscriptions->changePlan($this->account, $plan, $this->interval),
            "Your plan is now {$plan->name} ({$this->interval}ly). Any difference is prorated."
        );
    }

    public function cancel(SubscriptionService $subscriptions): void
    {
        $this->run(fn () => $subscriptions->cancel($this->account),
            'Subscription cancelled — you keep access until the end of the paid period.');
    }

    public function resume(SubscriptionService $subscriptions): void
    {
        $this->run(fn () => $subscriptions->resume($this->account),
            'Welcome back — your subscription has been resumed.');
    }

    public function pause(SubscriptionService $subscriptions): void
    {
        $this->run(fn () => $subscriptions->pause($this->account),
            'Billing paused. Your fleet keeps running; resume anytime.');
    }

    public function unpause(SubscriptionService $subscriptions): void
    {
        $this->run(fn () => $subscriptions->unpause($this->account),
            'Billing resumed.');
    }

    public function render()
    {
        return view('livewire.billing.subscription', [
            'plans' => app(PricingService::class)->plans(),
            'state' => app(SubscriptionService::class)->state($this->account),
        ])->layout('layouts.app');
    }
}
