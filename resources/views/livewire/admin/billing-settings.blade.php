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
                @if (! $hasKeys)
                    <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                        <p class="font-semibold mb-1">Stripe keys are not set.</p>
                        Add them to your <code class="font-mono text-xs">.env</code> (never commit them), then reload:
                        <pre class="mt-2 bg-white border border-amber-200 rounded p-2 text-xs overflow-x-auto">STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...</pre>
                        Get them from your Stripe dashboard → Developers → API keys. Use <strong>test</strong> keys first.
                    </div>
                @else
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200">Keys detected</span>
                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $isLive ? 'bg-red-50 text-red-700 border-red-200' : 'bg-blue-50 text-blue-700 border-blue-200' }}">
                            {{ $isLive ? 'LIVE mode' : 'TEST mode' }}
                        </span>
                        @if ($configured)
                            <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200">Checkout active</span>
                        @endif
                    </div>
                    <p class="text-xs text-slate-500 mt-2">
                        Webhook endpoint: <code class="font-mono">{{ route('billing.webhook') }}</code>
                        — add this in Stripe → Developers → Webhooks (event <code class="font-mono">checkout.session.completed</code>)
                        and put its signing secret in <code class="font-mono">STRIPE_WEBHOOK_SECRET</code>.
                    </p>
                @endif
            </div>

            {{-- Settings --}}
            <form wire:submit="save" class="pd-card p-6 space-y-4">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">Configuration</h3>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <x-checkbox wire:model="enabled" :disabled="! $hasKeys" />
                    Enable online payment (show subscribe buttons on the pricing page)
                </label>
                <div class="max-w-[10rem]">
                    <x-label for="currency" value="Currency (ISO)" />
                    <x-input id="currency" type="text" maxlength="3" class="mt-1 block w-full uppercase" wire:model="currency" placeholder="usd" />
                    <x-input-error for="currency" class="mt-1" />
                    <p class="text-xs text-slate-500 mt-1">Stripe supports 135+ currencies — e.g. usd, eur, gbp, inr, aud.</p>
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
