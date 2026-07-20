<?php

namespace App\Livewire\Marketing;

use App\Models\Signup;
use App\Services\BillingService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * The pricing page's "Get started", grown up: a public multi-step signup
 * that ends in payment and an application for the admin to approve —
 * instead of a contact form and a phone call.
 *
 * Steps: 1 fleet size -> 2 account -> 3 company -> 4 review & pay.
 * Each step validates only its own fields, so the visitor is corrected
 * where they are, not at the end. Nothing is written until the final
 * submit; abandoning the wizard leaves no residue.
 */
class SignupWizard extends Component
{
    public int $step = 1;

    public int $machines = 25;

    public string $contact_name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $company_name = '';

    public string $phone = '';

    public string $country = '';

    /**
     * card    — Stripe checkout with the 14-day trial (the default).
     * invoice — for organisations whose accounts department will not do
     *           cards; lands with the admin as an invoice request.
     * Only meaningful while Stripe is configured; without it every signup
     * is effectively the invoice path already.
     */
    public string $payVia = 'card';

    public function mount(): void
    {
        $machines = (int) request()->query('machines', '25');
        $this->machines = max(1, min(100000, $machines));
    }

    protected function rulesFor(int $step): array
    {
        return match ($step) {
            1 => ['machines' => ['required', 'integer', 'between:1,100000']],
            2 => [
                'contact_name' => ['required', 'string', 'max:120'],
                'email'        => ['required', 'email', 'max:190', 'unique:users,email'],
                // Matches the platform's password policy for created users.
                'password'     => ['required', 'string', 'min:10', 'confirmed', 'regex:/[a-zA-Z]/', 'regex:/[0-9]/'],
            ],
            3 => [
                'company_name' => ['required', 'string', 'max:150'],
                'phone'        => ['nullable', 'string', 'max:40'],
                'country'      => ['nullable', 'string', 'max:80'],
            ],
            default => [],
        };
    }

    public function next(): void
    {
        $this->validate($this->rulesFor($this->step));
        $this->step = min(4, $this->step + 1);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function goTo(int $step): void
    {
        // Backwards only — forward must pass each step's validation.
        if ($step < $this->step) {
            $this->step = $step;
        }
    }

    /**
     * Creates the application and hands off to payment. With Stripe
     * configured the visitor pays now and the webhook marks the signup
     * paid; without it the application lands as awaiting_verification and
     * payment is settled out of band (invoice) before approval.
     */
    public function submit(BillingService $billing, NotificationService $notifications)
    {
        // Everything, again: steps could be stale (an email registered
        // meanwhile) and the final write must not trust old validation.
        foreach ([1, 2, 3] as $step) {
            $this->validate($this->rulesFor($step));
        }

        if (Signup::where('email', $this->email)->where('status', '!=', Signup::STATUS_REJECTED)->exists()) {
            $this->addError('email', 'A signup with this email is already in progress. We will be in touch shortly.');

            return null;
        }

        $payByCard = $billing->isConfigured() && $this->payVia !== 'invoice';

        $signup = Signup::create([
            'company_name'  => $this->company_name,
            'contact_name'  => $this->contact_name,
            'email'         => $this->email,
            'password_hash' => Hash::make($this->password),
            'phone'         => $this->phone ?: null,
            'country'       => $this->country ?: null,
            'machines'      => $this->machines,
            'monthly_cents' => $billing->quoteCents($this->machines),
            'currency'      => $billing->currency(),
            'payment_method' => $payByCard ? 'card' : 'invoice',
            'status'        => $payByCard
                ? Signup::STATUS_PENDING_PAYMENT
                : Signup::STATUS_AWAITING_VERIFICATION,
        ]);

        // The plaintext password's job is done; drop it from component
        // state so it is not serialised back into the page.
        $this->reset('password', 'password_confirmation');

        $notifications->notify('signup.received', "New signup: {$signup->company_name} ({$signup->machines} machines)", [
            'company'  => $signup->company_name,
            'contact'  => $signup->contact_name,
            'email'    => $signup->email,
            'machines' => $signup->machines,
            'monthly'  => $signup->monthlyLabel(),
        ]);

        if ($payByCard) {
            $url = $billing->createCheckout(
                machines: $this->machines,
                successUrl: route('signup.thanks'),
                cancelUrl: route('pricing'),
                customerEmail: $signup->email,
                metadata: ['signup_id' => $signup->id],
            );

            if ($url !== null) {
                return redirect()->away($url);
            }

            // Stripe refused (transient): keep the application rather than
            // losing the customer at the last step.
            $signup->update(['status' => Signup::STATUS_AWAITING_VERIFICATION]);
        }

        return redirect()->route('signup.thanks');
    }

    public function render(BillingService $billing)
    {
        return view('livewire.marketing.signup-wizard', [
            'monthlyCents' => $billing->quoteCents($this->machines),
            'currency'     => strtoupper($billing->currency()),
            'paymentLive'  => $billing->isConfigured(),
        ])->layout('layouts.marketing-livewire', ['title' => 'Create your account — PioDeploy']);
    }
}
