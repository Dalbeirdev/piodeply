<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Subscription & Billing') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Already subscribed / on trial: show the current state. --}}
            @if ($state['subscribed'] || $state['status'] !== 'none')
                <div class="pd-card p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-slate-500">Current plan</p>
                            <p class="text-xl font-bold text-slate-900">{{ $state['plan']?->name ?? '—' }}</p>
                        </div>
                        <span @class([
                            'text-xs font-semibold rounded-full px-3 py-1 border',
                            'bg-teal-50 text-teal-700 border-teal-200'   => $state['status'] === 'trialing',
                            'bg-green-50 text-green-700 border-green-200' => $state['status'] === 'active',
                            'bg-amber-50 text-amber-700 border-amber-200' => in_array($state['status'], ['past_due','grace']),
                            'bg-red-50 text-red-600 border-red-200'       => in_array($state['status'], ['suspended','canceled']),
                        ])>{{ ucfirst($state['status']) }}</span>
                    </div>

                    @if ($state['on_trial'] && $state['trial_days_left'] !== null)
                        <p class="text-sm text-slate-600">
                            Trial ends in <strong>{{ $state['trial_days_left'] }}</strong>
                            {{ Str::plural('day', $state['trial_days_left']) }} — your card is charged automatically then.
                        </p>
                    @endif

                    <div>
                        <div class="flex justify-between text-sm text-slate-600 mb-1">
                            <span>Devices</span>
                            <span>{{ number_format($state['device_count']) }}@if ($state['device_limit'] !== null) / {{ number_format($state['device_limit']) }}@endif</span>
                        </div>
                        @if ($state['device_limit'] !== null)
                            @php $pct = min(100, (int) round($state['device_count'] / max(1, $state['device_limit']) * 100)); @endphp
                            <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-full {{ $state['over_limit'] ? 'bg-red-500' : 'bg-teal-600' }}" style="width: {{ $pct }}%"></div>
                            </div>
                            @if ($state['over_limit'])
                                <p class="text-xs text-red-600 mt-1">Over your plan's device limit — new machines are blocked from enrolling until you upgrade or raise the override.</p>
                            @endif
                        @endif

                        {{-- Admin override of the device ceiling (Module 11) --}}
                        <div class="mt-3 flex flex-wrap items-end gap-2">
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">Device limit override</label>
                                <input type="number" min="1" wire:model="overrideLimit" placeholder="plan limit"
                                       class="mt-1 w-32 rounded-lg border-slate-300 text-sm">
                            </div>
                            <button type="button" wire:click="saveDeviceLimit"
                                    class="px-3 py-2 text-sm font-semibold text-teal-700 border border-teal-300 rounded-lg hover:bg-teal-50">Apply</button>
                            @if ($account->device_limit_overridden)
                                <button type="button" wire:click="$set('overrideLimit', null); saveDeviceLimit()"
                                        class="px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700">Reset to plan</button>
                                <span class="text-xs text-amber-600">Overriding the plan limit.</span>
                            @endif
                            @error('overrideLimit') <p class="text-xs text-red-600 w-full">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($state['on_grace'] && $state['ends_at'])
                        <p class="text-sm text-amber-700">Cancelled — access continues until {{ $state['ends_at']->toFormattedDayDateString() }}.</p>
                    @endif
                    @if ($state['paused'])
                        <p class="text-sm text-slate-600">Billing is paused. Your fleet keeps running.</p>
                    @endif

                    @if ($errorMessage)
                        <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ $errorMessage }}</div>
                    @endif

                    {{-- Change plan --}}
                    @if ($state['can_change'])
                        <div class="border-t border-slate-100 pt-4 space-y-3">
                            <p class="text-sm font-semibold text-slate-700">Change plan</p>
                            <div class="inline-flex gap-1 p-1 bg-slate-100 rounded-full border border-slate-200 text-sm">
                                <button type="button" wire:click="$set('interval','month')"
                                    @class(['px-3 py-1 rounded-full font-semibold', 'bg-white text-teal-700 shadow-sm' => $interval==='month', 'text-slate-600' => $interval!=='month'])>Monthly</button>
                                <button type="button" wire:click="$set('interval','year')"
                                    @class(['px-3 py-1 rounded-full font-semibold', 'bg-white text-teal-700 shadow-sm' => $interval==='year', 'text-slate-600' => $interval!=='year'])>Yearly</button>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <select wire:model.live="planId" class="rounded-lg border-slate-300 text-sm">
                                    @foreach ($plans as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }} — ${{ number_format(($interval==='year'?$plan->yearly_price_cents:$plan->monthly_price_cents)/100, 0) }}/{{ $interval }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="changePlan"
                                    wire:confirm="Change plan? The price difference is prorated on your next invoice."
                                    class="px-4 py-2 bg-teal-700 text-white rounded-lg text-sm font-semibold hover:bg-teal-800">Change plan</button>
                            </div>
                            <p class="text-xs text-slate-400">Upgrades and downgrades take effect immediately and are prorated.</p>
                        </div>
                    @endif

                    {{-- Cancel / resume / pause --}}
                    <div class="border-t border-slate-100 pt-4 flex flex-wrap gap-2">
                        @if ($state['can_pause'])
                            <button type="button" wire:click="pause"
                                wire:confirm="Pause billing? No charges until you resume; your fleet keeps running."
                                class="px-3 py-2 text-sm font-semibold text-slate-600 border border-slate-300 rounded-lg hover:border-slate-400">Pause billing</button>
                        @endif
                        @if ($state['paused'])
                            <button type="button" wire:click="unpause"
                                class="px-3 py-2 text-sm font-semibold text-teal-700 border border-teal-300 rounded-lg hover:bg-teal-50">Resume billing</button>
                        @endif
                        @if ($state['can_resume'])
                            <button type="button" wire:click="resume"
                                class="px-3 py-2 text-sm font-semibold text-white bg-teal-700 rounded-lg hover:bg-teal-800">Resume subscription</button>
                        @endif
                        @if ($state['can_cancel'])
                            <button type="button" wire:click="cancel"
                                wire:confirm="Cancel your subscription? You keep access until the end of the current paid period."
                                class="px-3 py-2 text-sm font-semibold text-red-600 border border-red-200 rounded-lg hover:bg-red-50">Cancel subscription</button>
                        @endif
                    </div>

                    <p class="text-xs text-slate-400">
                        <a href="{{ route('billing.invoices') }}" class="pd-link">Invoices, payment method &amp; billing history →</a>
                    </p>
                </div>
            @elseif (! $this->billingConfigured())
                <div class="pd-card p-6">
                    <p class="font-semibold text-amber-700">Billing isn't configured yet</p>
                    <p class="text-sm text-slate-600 mt-1">
                        Add your Stripe keys (<code>STRIPE_KEY</code>, <code>STRIPE_SECRET</code>) to <code>.env</code>
                        and run <code>php artisan billing:sync-stripe</code>, then reload this page to start a trial.
                    </p>
                </div>
            @else
                {{-- Choose a plan + interval, verify a card, start the trial. --}}
                <div class="pd-card p-6 space-y-5">
                    <div>
                        <h3 class="font-semibold text-slate-800">Start your 14-day free trial</h3>
                        <p class="text-sm text-slate-500">Card required to prevent abuse. You're not charged until the trial ends; cancel anytime.</p>
                    </div>

                    {{-- Interval toggle --}}
                    <div class="inline-flex gap-1 p-1 bg-slate-100 rounded-full border border-slate-200">
                        <button type="button" wire:click="$set('interval','month')"
                            @class(['px-4 py-1.5 rounded-full text-sm font-semibold', 'bg-white text-teal-700 shadow-sm' => $interval==='month', 'text-slate-600' => $interval!=='month'])>Monthly</button>
                        <button type="button" wire:click="$set('interval','year')"
                            @class(['px-4 py-1.5 rounded-full text-sm font-semibold', 'bg-white text-teal-700 shadow-sm' => $interval==='year', 'text-slate-600' => $interval!=='year'])>Yearly <span class="text-emerald-600">−2 mo</span></button>
                    </div>

                    {{-- Plan chooser --}}
                    <div class="grid sm:grid-cols-2 gap-3">
                        @foreach ($plans as $plan)
                            <label @class([
                                'flex items-center justify-between p-3 rounded-xl border cursor-pointer transition',
                                'border-teal-500 ring-1 ring-teal-500 bg-teal-50/40' => $planId === $plan->id,
                                'border-slate-200 hover:border-teal-300' => $planId !== $plan->id,
                            ])>
                                <div>
                                    <span class="font-semibold text-slate-800">{{ $plan->name }}</span>
                                    @if ($plan->is_recommended)<span class="ml-1 text-[10px] uppercase font-bold text-teal-700">Popular</span>@endif
                                    <p class="text-xs text-slate-500">up to {{ number_format($plan->device_limit) }} machines</p>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold text-slate-900">${{ number_format(($interval === 'year' ? $plan->yearly_price_cents : $plan->monthly_price_cents) / 100, 0) }}</span>
                                    <span class="text-xs text-slate-400 block">/ {{ $interval }}</span>
                                </div>
                                <input type="radio" wire:model.live="planId" value="{{ $plan->id }}" class="sr-only">
                            </label>
                        @endforeach
                    </div>
                    @error('planId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                    {{-- Card element (kept out of Livewire's DOM diffing). --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Card details</label>
                        <div wire:ignore>
                            <div id="card-element" class="p-3 border border-slate-300 rounded-lg bg-white"></div>
                        </div>
                        <p id="card-errors" class="text-xs text-red-600 mt-1"></p>
                    </div>

                    @if ($errorMessage)
                        <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ $errorMessage }}</div>
                    @endif

                    <button type="button" id="start-trial-btn"
                        class="w-full inline-flex justify-center items-center gap-2 px-4 py-2.5 bg-teal-700 text-white rounded-lg font-semibold hover:bg-teal-800 disabled:opacity-60">
                        <span id="btn-label">Start 14-day trial</span>
                    </button>
                    <p class="text-center text-xs text-slate-400">Secured by Stripe. We never see or store your card number.</p>
                </div>

                @assets
                    <script src="https://js.stripe.com/v3/"></script>
                @endassets

                @script
                <script>
                    const stripe = Stripe(@js(config('cashier.key')));
                    const elements = stripe.elements();
                    const card = elements.create('card', { hidePostalCode: false });
                    card.mount('#card-element');
                    card.on('change', (e) => {
                        document.getElementById('card-errors').textContent = e.error ? e.error.message : '';
                    });

                    const btn = document.getElementById('start-trial-btn');
                    const label = document.getElementById('btn-label');

                    btn.addEventListener('click', async () => {
                        const secret = $wire.stripeClientSecret;
                        if (!secret) { return; }
                        btn.disabled = true; label.textContent = 'Verifying card…';

                        const { setupIntent, error } = await stripe.confirmCardSetup(secret, {
                            payment_method: { card }
                        });

                        if (error) {
                            document.getElementById('card-errors').textContent = error.message;
                            btn.disabled = false; label.textContent = 'Start 14-day trial';
                            return;
                        }

                        label.textContent = 'Starting trial…';
                        $wire.set('paymentMethod', setupIntent.payment_method);
                        await $wire.startTrial();
                        btn.disabled = false; label.textContent = 'Start 14-day trial';
                    });
                </script>
                @endscript
            @endif
        </div>
    </div>
</div>
