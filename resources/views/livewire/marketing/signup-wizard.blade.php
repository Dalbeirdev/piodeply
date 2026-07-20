<div>
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">Get started</span>
        <h1>Create your PioDeploy account</h1>
        <p class="muted" style="max-width:52ch;margin:1rem auto 0;font-size:1.1rem;">
            Four quick steps. Your account goes live as soon as our team verifies the payment —
            usually within one business day.
        </p>
    </div>
</section>

<section class="section">
    <div class="container" style="max-width:640px;">

        {{-- Step rail --}}
        <div style="display:flex;gap:.5rem;margin-bottom:1.5rem;" aria-label="Signup progress">
            @foreach (['Fleet size', 'Your account', 'Company', 'Review & pay'] as $i => $label)
                @php $n = $i + 1; @endphp
                <button type="button" @if($n < $step) wire:click="goTo({{ $n }})" @endif
                        style="flex:1;text-align:center;padding:.5rem .25rem;border:0;border-radius:.5rem;font-size:.8rem;font-weight:600;cursor:{{ $n < $step ? 'pointer' : 'default' }};
                               background:{{ $n === $step ? 'var(--teal, #0f766e)' : ($n < $step ? '#d1fae5' : '#f1f5f9') }};
                               color:{{ $n === $step ? '#fff' : ($n < $step ? '#047857' : '#94a3b8') }};">
                    {{ $n }}. {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="form-card">
            @if ($step === 1)
                <div class="field">
                    <label for="machines">How many machines will you manage?</label>
                    <input id="machines" type="number" min="1" max="100000" wire:model.live.debounce.400ms="machines">
                    @error('machines')<div class="err">{{ $message }}</div>@enderror
                </div>
                <p class="muted" style="margin:.75rem 0 1.25rem;">
                    Your monthly price:
                    <strong style="font-size:1.3rem;">{{ $currency }} {{ number_format($monthlyCents / 100, 2) }}</strong>
                    <span style="font-size:.85rem;">/ month for {{ $machines }} machines</span>
                </p>
                <button type="button" wire:click="next" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">Continue</button>

            @elseif ($step === 2)
                <div class="field">
                    <label for="contact_name">Your name</label>
                    <input id="contact_name" wire:model="contact_name" autocomplete="name">
                    @error('contact_name')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="email">Work email <span class="muted" style="font-weight:400;">(this becomes your login)</span></label>
                    <input id="email" type="email" wire:model="email" autocomplete="email">
                    @error('email')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="password">Password <span class="muted" style="font-weight:400;">(10+ characters, letters and numbers)</span></label>
                    <input id="password" type="password" wire:model="password" autocomplete="new-password">
                    @error('password')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password">
                </div>
                <div style="display:flex;gap:.75rem;">
                    <button type="button" wire:click="back" class="btn btn-lg" style="flex:1;justify-content:center;">Back</button>
                    <button type="button" wire:click="next" class="btn btn-primary btn-lg" style="flex:2;justify-content:center;">Continue</button>
                </div>

            @elseif ($step === 3)
                <div class="field">
                    <label for="company_name">Company name</label>
                    <input id="company_name" wire:model="company_name" autocomplete="organization">
                    @error('company_name')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="phone">Phone <span class="muted" style="font-weight:400;">(optional)</span></label>
                    <input id="phone" wire:model="phone" autocomplete="tel">
                    @error('phone')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="country">Country <span class="muted" style="font-weight:400;">(optional)</span></label>
                    <input id="country" wire:model="country" autocomplete="country-name">
                    @error('country')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div style="display:flex;gap:.75rem;">
                    <button type="button" wire:click="back" class="btn btn-lg" style="flex:1;justify-content:center;">Back</button>
                    <button type="button" wire:click="next" class="btn btn-primary btn-lg" style="flex:2;justify-content:center;">Continue</button>
                </div>

            @else
                <h3 style="margin-top:0;">Review</h3>
                <table style="width:100%;font-size:.95rem;border-collapse:collapse;">
                    <tr><td class="muted" style="padding:.35rem 0;">Company</td><td style="text-align:right;font-weight:600;">{{ $company_name }}</td></tr>
                    <tr><td class="muted" style="padding:.35rem 0;">Contact</td><td style="text-align:right;font-weight:600;">{{ $contact_name }} · {{ $email }}</td></tr>
                    <tr><td class="muted" style="padding:.35rem 0;">Fleet</td><td style="text-align:right;font-weight:600;">{{ $machines }} machines</td></tr>
                    <tr><td class="muted" style="padding:.35rem 0;">Monthly</td><td style="text-align:right;font-weight:700;font-size:1.15rem;">{{ $currency }} {{ number_format($monthlyCents / 100, 2) }}</td></tr>
                </table>
                <p class="muted" style="font-size:.85rem;margin:1rem 0;">
                    @if ($paymentLive)
                        You'll be taken to our secure Stripe checkout. After payment, our team verifies it and
                        activates your account — you'll get an email the moment you can sign in.
                    @else
                        We'll send an invoice for your first month. Your account is activated as soon as
                        payment is confirmed — you'll get an email the moment you can sign in.
                    @endif
                </p>
                @error('email')<div class="err" style="margin-bottom:.75rem;">{{ $message }}</div>@enderror
                <div style="display:flex;gap:.75rem;">
                    <button type="button" wire:click="back" class="btn btn-lg" style="flex:1;justify-content:center;">Back</button>
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" class="btn btn-primary btn-lg" style="flex:2;justify-content:center;">
                        <span wire:loading.remove wire:target="submit">{{ $paymentLive ? 'Continue to payment' : 'Submit application' }}</span>
                        <span wire:loading wire:target="submit">One moment…</span>
                    </button>
                </div>
            @endif
        </div>

        <p class="muted" style="text-align:center;font-size:.85rem;margin-top:1rem;">
            Prefer to talk first? <a href="{{ route('contact') }}" class="tlink">Contact us</a> ·
            Already have an account? <a href="{{ route('login') }}" class="tlink">Sign in</a>
        </p>
    </div>
</section>
</div>
