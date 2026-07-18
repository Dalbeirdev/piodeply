<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Affiliate Dashboard') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (! $affiliate)
                <div class="pd-card p-8 text-center">
                    <p class="font-semibold text-slate-700">You're not an affiliate yet</p>
                    <p class="text-sm text-slate-500 mt-1">Ask an administrator to set up a referral account for you.</p>
                </div>
            @else
                @if ($message)
                    <div class="rounded-md bg-teal-50 border border-teal-200 p-3 text-sm text-teal-700">{{ $message }}</div>
                @endif

                {{-- Referral link --}}
                <div class="pd-card p-6" x-data="{ copied: false }">
                    <p class="text-sm font-semibold text-slate-700">Your referral link</p>
                    <div class="flex items-center gap-2 mt-2">
                        <code class="flex-1 text-sm font-mono bg-slate-50 border border-slate-200 rounded px-3 py-2 select-all break-all">{{ $affiliate->referralUrl() }}</code>
                        <button type="button" class="text-sm pd-link"
                            x-on:click="navigator.clipboard.writeText(@js($affiliate->referralUrl())); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        You earn {{ $affiliate->commission_type === 'fixed' ? '$'.number_format($affiliate->commission_rate/100,2).' per referral' : $affiliate->commission_rate.'% commission' }}{{ $affiliate->recurring ? ', recurring' : '' }}.
                    </p>
                </div>

                {{-- Stats --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="pd-card p-4"><p class="text-xl font-bold text-slate-800">{{ $stats['clicks'] }}</p><p class="text-xs font-semibold text-slate-600">Clicks</p></div>
                    <div class="pd-card p-4"><p class="text-xl font-bold text-slate-800">{{ $stats['conversions'] }}</p><p class="text-xs font-semibold text-slate-600">Conversions</p></div>
                    <div class="pd-card p-4"><p class="text-xl font-bold text-slate-800">${{ number_format($stats['revenue_cents']/100,2) }}</p><p class="text-xs font-semibold text-slate-600">Revenue referred</p></div>
                    <div class="pd-card p-4"><p class="text-xl font-bold text-emerald-700">${{ number_format($stats['available_cents']/100,2) }}</p><p class="text-xs font-semibold text-slate-600">Available</p></div>
                </div>

                <div class="pd-card p-6 flex items-center justify-between">
                    <div class="text-sm text-slate-600">
                        Pending ${{ number_format($stats['pending_cents']/100,2) }} ·
                        Approved ${{ number_format($stats['approved_cents']/100,2) }} ·
                        Paid ${{ number_format($stats['paid_cents']/100,2) }}
                    </div>
                    <button type="button" wire:click="requestPayout" @disabled($stats['available_cents'] < 1)
                        class="px-4 py-2 bg-teal-700 text-white rounded-lg text-sm font-semibold hover:bg-teal-800 disabled:opacity-50">Request payout</button>
                </div>

                {{-- Commissions --}}
                <div class="pd-card">
                    <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Recent commissions</h3></div>
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50"><tr><th class="pd-th">When</th><th class="pd-th">Commission</th><th class="pd-th">Status</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($commissions as $c)
                                <tr>
                                    <td class="px-6 py-2 text-slate-500">{{ $c->created_at->toFormattedDateString() }}</td>
                                    <td class="px-6 py-2 font-semibold text-slate-800">${{ number_format($c->amount_cents/100,2) }}</td>
                                    <td class="px-6 py-2 capitalize">{{ $c->status }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-6 py-6 text-center text-slate-500">No commissions yet — share your link to start earning.</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>
                </div>
            @endif
        </div>
    </div>
</div>
