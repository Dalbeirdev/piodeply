<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Invoices & Payment') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif
            @if ($errorMessage)
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ $errorMessage }}</div>
            @endif

            @unless ($this->billingConfigured())
                <div class="pd-card p-6">
                    <p class="font-semibold text-amber-700">Billing isn't configured yet</p>
                    <p class="text-sm text-slate-600 mt-1">Invoices and payment methods appear once Stripe keys are set.</p>
                </div>
            @else
                {{-- Payment method --}}
                <div class="pd-card p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-slate-800">Payment method</h3>
                    </div>

                    @if ($defaultPm)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="px-2 py-1 rounded bg-slate-100 font-mono uppercase text-xs">{{ $defaultPm->card->brand }}</span>
                            <span class="text-slate-700">•••• {{ $defaultPm->card->last4 }}</span>
                            <span class="text-slate-400">expires {{ $defaultPm->card->exp_month }}/{{ $defaultPm->card->exp_year }}</span>
                            <span class="text-xs text-teal-700 font-semibold">Default</span>
                        </div>
                    @else
                        <p class="text-sm text-slate-500">No card on file.</p>
                    @endif

                    {{-- Other cards --}}
                    @foreach ($cards as $card)
                        @continue($defaultPm && $card->id === $defaultPm->id)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="px-2 py-1 rounded bg-slate-100 font-mono uppercase text-xs">{{ $card->card->brand }}</span>
                            <span class="text-slate-700">•••• {{ $card->card->last4 }}</span>
                            <button type="button" wire:click="setDefault('{{ $card->id }}')" class="text-xs text-teal-700 font-semibold hover:underline">Make default</button>
                            <button type="button" wire:click="removeCard('{{ $card->id }}')" class="text-xs text-red-600 font-semibold hover:underline">Remove</button>
                        </div>
                    @endforeach

                    {{-- Add / update card --}}
                    <div x-data="{ open: false }">
                        <button type="button" x-on:click="open = !open" class="text-sm font-semibold text-teal-700 hover:text-teal-900">
                            <span x-text="open ? 'Cancel' : ($wire.get('paymentMethod') ? 'Change card' : 'Add / update card')">Add / update card</span>
                        </button>
                        <div x-show="open" x-cloak class="mt-3 space-y-3">
                            <div wire:ignore>
                                <div id="pm-card-element" class="p-3 border border-slate-300 rounded-lg bg-white"></div>
                            </div>
                            <p id="pm-card-errors" class="text-xs text-red-600"></p>
                            <button type="button" id="save-card-btn" class="px-4 py-2 bg-teal-700 text-white rounded-lg text-sm font-semibold hover:bg-teal-800">
                                <span id="save-card-label">Save card</span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Upcoming invoice --}}
                @if ($upcoming)
                    <div class="pd-card p-6">
                        <h3 class="font-semibold text-slate-800 mb-1">Upcoming charge</h3>
                        <p class="text-sm text-slate-600">
                            <span class="text-lg font-bold text-slate-900">{{ $upcoming->total() }}</span>
                            on {{ $upcoming->date()?->toFormattedDayDateString() }}
                        </p>
                    </div>
                @endif

                {{-- Invoice history --}}
                <div class="pd-card">
                    <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Billing history</h3></div>
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50"><tr>
                            <th class="pd-th">Date</th><th class="pd-th">Total</th><th class="pd-th">Status</th><th class="px-6 py-3"></th>
                        </tr></thead>
                        <tbody class="bg-white divide-y divide-slate-100">
                            @forelse ($invoices as $invoice)
                                <tr>
                                    <td class="px-6 py-3 text-sm text-slate-700">{{ $invoice->date()?->toFormattedDateString() }}</td>
                                    <td class="px-6 py-3 text-sm text-slate-700">{{ $invoice->total() }}</td>
                                    <td class="px-6 py-3">
                                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200 capitalize">{{ $invoice->asStripeInvoice()->status ?? 'paid' }}</span>
                                    </td>
                                    <td class="px-6 py-3 text-right">
                                        <a href="{{ route('billing.invoices.download', $invoice->id) }}" class="text-xs font-semibold text-teal-700 hover:text-teal-900">Download PDF</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">No invoices yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>
                </div>

                @if ($stripeClientSecret)
                    @assets
                        <script src="https://js.stripe.com/v3/"></script>
                    @endassets
                    @script
                    <script>
                        const stripe = Stripe(@js(config('cashier.key')));
                        const card = stripe.elements().create('card');
                        card.mount('#pm-card-element');
                        card.on('change', (e) => { document.getElementById('pm-card-errors').textContent = e.error ? e.error.message : ''; });

                        const btn = document.getElementById('save-card-btn');
                        const label = document.getElementById('save-card-label');
                        btn?.addEventListener('click', async () => {
                            btn.disabled = true; label.textContent = 'Verifying…';
                            const { setupIntent, error } = await stripe.confirmCardSetup($wire.stripeClientSecret, { payment_method: { card } });
                            if (error) {
                                document.getElementById('pm-card-errors').textContent = error.message;
                                btn.disabled = false; label.textContent = 'Save card';
                                return;
                            }
                            $wire.set('paymentMethod', setupIntent.payment_method);
                            await $wire.saveCard();
                            btn.disabled = false; label.textContent = 'Save card';
                        });
                    </script>
                    @endscript
                @endif
            @endunless
        </div>
    </div>
</div>
