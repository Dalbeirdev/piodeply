<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Billing & payments') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Connection status --}}
            <div class="pd-card p-6">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Stripe connection</h3>
                <div class="flex items-center gap-2 text-sm flex-wrap">
                    @if ($hasKeys)
                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200">Keys detected</span>
                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $isLive ? 'bg-red-50 text-red-700 border-red-200' : 'bg-blue-50 text-blue-700 border-blue-200' }}">
                            {{ $isLive ? 'LIVE mode' : 'TEST mode' }}
                        </span>
                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $hasWebhookSecret ? 'bg-green-50 text-green-700 border-green-200' : 'bg-amber-50 text-amber-700 border-amber-200' }}">
                            {{ $hasWebhookSecret ? 'Webhook secret set' : 'Webhook secret missing' }}
                        </span>
                    @else
                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-amber-50 text-amber-700 border-amber-200">Not configured — enter your keys below</span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 mt-3">
                    Subscription webhook endpoint (add in Stripe → Developers → Webhooks):
                    <code class="font-mono">{{ url('/stripe/webhook') }}</code>.
                    After creating the keys below, run <code class="font-mono">php artisan billing:sync-stripe</code> once to create the Stripe products &amp; prices.
                </p>
            </div>

            {{-- Settings --}}
            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">Stripe API keys</h3>
                <p class="text-xs text-slate-500 -mt-2">
                    From Stripe → Developers → API keys. Use <strong>test</strong> keys first. Secrets are encrypted at rest
                    and never shown again — leave a secret field blank to keep the stored value.
                </p>

                <div>
                    <x-label for="pk" value="Publishable key" />
                    <x-input id="pk" type="text" class="mt-1 block w-full font-mono text-sm" wire:model="publishableKey" placeholder="pk_test_..." autocomplete="off" />
                    <x-input-error for="publishableKey" class="mt-1" />
                </div>
                <div>
                    <x-label for="sk" value="Secret key" />
                    <x-input id="sk" type="password" class="mt-1 block w-full font-mono text-sm" wire:model="secretKey"
                             placeholder="{{ $hasSecret ? '•••••••• (stored — leave blank to keep)' : 'sk_test_...' }}" autocomplete="off" />
                    <x-input-error for="secretKey" class="mt-1" />
                </div>
                <div>
                    <x-label for="whsec" value="Webhook signing secret" />
                    <x-input id="whsec" type="password" class="mt-1 block w-full font-mono text-sm" wire:model="webhookSecret"
                             placeholder="{{ $hasWebhookSecret ? '•••••••• (stored — leave blank to keep)' : 'whsec_...' }}" autocomplete="off" />
                    <x-input-error for="webhookSecret" class="mt-1" />
                    <p class="text-xs text-slate-500 mt-1">Shown once when you create the webhook endpoint in Stripe.</p>
                </div>

                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider border-t pt-4">Configuration</h3>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <x-checkbox wire:model="enabled" />
                    Enable the legacy per-machine checkout on the marketing site (subscription plans do not need this)
                </label>
                <div class="max-w-[10rem]">
                    <x-label for="currency" value="Currency (ISO)" />
                    <x-input id="currency" type="text" maxlength="3" class="mt-1 block w-full uppercase" wire:model="currency" placeholder="usd" />
                    <x-input-error for="currency" class="mt-1" />
                    <p class="text-xs text-slate-500 mt-1">Stripe supports 135+ currencies — e.g. usd, eur, gbp, inr, aud.</p>
                </div>
                <div class="max-w-[10rem]">
                    <x-label for="clientGraceDays" value="Dunning grace (days)" />
                    <x-input id="clientGraceDays" type="number" min="3" max="60" class="mt-1 block w-full" wire:model="clientGraceDays" />
                    <x-input-error for="clientGraceDays" class="mt-1" />
                    <p class="text-xs text-slate-500 mt-1">How long a client can stay past-due before their account is suspended. Reminders go out every 3 days meanwhile; paying reactivates automatically.</p>
                </div>
                <div class="text-sm text-slate-600">
                    <span class="font-semibold">Graduated per-machine schedule</span> (monthly):
                    @php $prev = 0; @endphp
                    @foreach ($tiers as $t)
                        <span class="inline-block text-xs bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5 ml-1">
                            {{ $t['up_to'] ? ($prev + 1) . '–' . $t['up_to'] : ($prev . '+') }}: ${{ number_format($t['unit'] / 100, 2) }}
                        </span>
                        @php $prev = $t['up_to'] ?? $prev; @endphp
                    @endforeach
                    <p class="text-xs text-slate-400 mt-1">Defined in code (BillingService::TIERS) to keep pricing consistent with the site.</p>
                </div>
                <div class="flex justify-end border-t pt-4">
                    <x-button>Save billing settings</x-button>
                </div>
            </form>

            {{-- Recent payments --}}
            <div class="pd-card">
                <div class="px-6 pt-5 pb-3"><h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Recent payments</h3></div>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50"><tr>
                        <th class="pd-th">When</th><th class="pd-th">Email</th><th class="pd-th">Plan</th>
                        <th class="pd-th">Machines</th><th class="pd-th">Amount</th><th class="pd-th">Status</th>
                    </tr></thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($payments as $payment)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm">{{ $payment->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-700 text-sm">{{ $payment->customer_email ?? '—' }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">{{ ucfirst($payment->plan ?? '—') }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">{{ $payment->quantity ?? '—' }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-700 text-sm">
                                    {{ $payment->amount_total ? strtoupper($payment->currency) . ' ' . number_format($payment->amount_total / 100, 2) : '—' }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $payment->status === 'paid' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">{{ ucfirst($payment->status) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
