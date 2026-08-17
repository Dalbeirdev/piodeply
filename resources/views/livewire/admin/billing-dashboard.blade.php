@php $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2); @endphp
<div>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Billing Overview') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">Revenue, subscriptions and payments across every client.</p>
            </div>
            <a href="{{ route('billing.export') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                Export payments (CSV)
            </a>
        </div>
    </x-slot>

    @php
        $tiles = [
            ['label' => 'MRR',            'sub' => 'Monthly recurring revenue', 'value' => $m['mrr_cents'],     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>'],
            ['label' => 'ARR',            'sub' => 'Annual run rate',           'value' => $m['arr_cents'],     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>'],
            ['label' => 'Total revenue',  'sub' => 'All time',                  'value' => $m['revenue_cents'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
            ['label' => 'Lifetime value', 'sub' => 'Average per customer',      'value' => $m['ltv_cents'],     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25 12 3l9.75 5.25L12 13.5 2.25 8.25Zm0 0V15l9.75 5.25L21.75 15V8.25"/>'],
        ];
    @endphp

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            {{-- Headline numbers --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                @foreach ($tiles as $tile)
                    <div class="pd-card p-5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 text-teal-700 border border-teal-100 mb-3">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $tile['icon'] !!}</svg>
                        </span>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ $money($tile['value']) }}</p>
                        <p class="text-sm font-semibold text-slate-700 mt-1">{{ $tile['label'] }}</p>
                        <p class="text-xs text-slate-400">{{ $tile['sub'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Revenue over time --}}
            <div class="pd-card p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h3 class="font-semibold text-slate-800">Revenue</h3>
                        <div class="flex items-baseline gap-3 mt-1 flex-wrap">
                            <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ $money($periodCents) }}</p>
                            @if ($trendPercent !== null)
                                <span class="text-xs font-semibold rounded-full px-2 py-0.5 border
                                             {{ $trendPercent >= 0 ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' }}">
                                    {{ $trendPercent >= 0 ? '↑' : '↓' }} {{ abs($trendPercent) }}% vs previous {{ $months }} months
                                </span>
                            @else
                                <span class="text-xs text-slate-400">no earlier period to compare</span>
                            @endif
                        </div>
                    </div>
                    <select wire:model.live="months"
                            class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-lg shadow-sm text-sm shrink-0">
                        <option value="12">Last 12 months</option>
                        <option value="6">Last 6 months</option>
                        <option value="3">Last 3 months</option>
                    </select>
                </div>

                <div class="flex items-end gap-2 h-40 mt-5">
                    @foreach ($series as $point)
                        @php $h = (int) round(($point['cents'] / $seriesMax) * 100); @endphp
                        <div class="flex-1 flex flex-col items-center justify-end gap-1 group">
                            <div class="w-full rounded-t bg-teal-500/80 group-hover:bg-teal-600 transition-colors"
                                 style="height: {{ max($h, 1) }}%" title="{{ $point['month'] }}: {{ $money($point['cents']) }}"></div>
                            <span class="text-[10px] text-slate-400">{{ $point['month'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Subscription funnel + health --}}
            <div class="grid md:grid-cols-2 gap-6">
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 mb-3">Subscriptions</h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        @foreach (['active' => 'Active', 'trialing' => 'Trialing', 'past_due' => 'Past due', 'paused' => 'Paused', 'grace' => 'Grace', 'canceled' => 'Cancelled', 'suspended' => 'Suspended'] as $key => $label)
                            <div class="flex items-center justify-between border-b border-slate-100 py-1">
                                <span class="text-slate-500">{{ $label }}</span>
                                <span class="font-semibold text-slate-800">{{ $m['status'][$key] ?? 0 }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 mb-3">Health</h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="flex items-center justify-between border-b border-slate-100 py-1"><span class="text-slate-500">Active trials</span><span class="font-semibold text-slate-800">{{ $m['active_trials'] }}</span></div>
                        <div class="flex items-center justify-between border-b border-slate-100 py-1"><span class="text-slate-500">Expired trials</span><span class="font-semibold text-slate-800">{{ $m['expired_trials'] }}</span></div>
                        <div class="flex items-center justify-between border-b border-slate-100 py-1"><span class="text-slate-500">Payment issues</span><span class="font-semibold {{ $m['payment_issues'] ? 'text-amber-600' : 'text-slate-800' }}">{{ $m['payment_issues'] }}</span></div>
                        <div class="flex items-center justify-between border-b border-slate-100 py-1"><span class="text-slate-500">Refunds</span><span class="font-semibold text-slate-800">{{ $m['refunds'] }}</span></div>
                        <div class="flex items-center justify-between border-b border-slate-100 py-1"><span class="text-slate-500">Churn</span><span class="font-semibold text-slate-800">{{ $m['churn_percent'] }}%</span></div>
                        <div class="flex items-center justify-between border-b border-slate-100 py-1"><span class="text-slate-500">Cancelled</span><span class="font-semibold text-slate-800">{{ $m['cancelled'] }}</span></div>
                    </div>
                </div>
            </div>

            {{-- Coupons + affiliates --}}
            <div class="grid md:grid-cols-2 gap-6">
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-700">Coupons</h3>
                        <a href="{{ route('admin.coupons') }}" class="text-xs pd-link">Manage →</a>
                    </div>
                    <p class="text-sm text-slate-600">{{ $m['coupons']['redemptions'] }} redemptions · {{ $m['coupons']['active'] }} active · {{ $money($m['coupons']['discount_cents']) }} discounted</p>
                </div>
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-700">Affiliates</h3>
                        <a href="{{ route('admin.affiliates') }}" class="text-xs pd-link">Manage →</a>
                    </div>
                    <p class="text-sm text-slate-600">{{ $m['affiliates']['affiliates'] }} affiliates · pending {{ $money($m['affiliates']['pending_cents']) }} · approved {{ $money($m['affiliates']['approved_cents']) }} · paid {{ $money($m['affiliates']['paid_cents']) }}</p>
                </div>
            </div>

            {{-- Next payments: money expected in, beside the money already taken. --}}
            <div class="pd-card">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-semibold text-slate-800">Next payments</h3>
                    @if ($renewalCents > 0)
                        <span class="text-sm text-slate-500">
                            {{ $renewals->count() }} due ·
                            <span class="font-semibold text-slate-800 tabular-nums">${{ number_format($renewalCents / 100, 2) }}</span> expected
                        </span>
                    @endif
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($renewals as $client)
                        <li>
                            {{-- clients.edit is the client's detail page; there is
                                 no separate show route. --}}
                            <a href="{{ route('clients.edit', $client) }}"
                               class="px-6 py-3 flex items-center gap-4 hover:bg-slate-50/60 transition-colors">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-50 border border-teal-100
                                             text-teal-700 text-xs font-bold uppercase">
                                    {{ Str::of($client->company_name)->trim()->substr(0, 2) }}
                                </span>
                                <div class="min-w-0 grow">
                                    <p class="text-sm font-medium text-slate-800 truncate">{{ $client->company_name }}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        {{ $client->subscription_period_end->format('j M Y') }}
                                        <span class="mx-1">·</span>{{ $client->subscription_period_end->diffForHumans() }}
                                        @if ($client->subscription_status === 'trialing')
                                            <span class="mx-1">·</span><span class="text-teal-700 font-medium">first charge after trial</span>
                                        @endif
                                    </p>
                                </div>
                                <span class="text-sm font-semibold text-slate-800 tabular-nums shrink-0">
                                    {{ $client->subscription_cents ? '$'.number_format($client->subscription_cents / 100, 2) : '—' }}
                                </span>
                                <svg class="h-4 w-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </a>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-center text-slate-500 text-sm">
                            No renewals scheduled.
                            <span class="block text-xs text-slate-400 mt-1">Active subscriptions show their next charge date here.</span>
                        </li>
                    @endforelse
                </ul>
            </div>

            {{-- Recent payments --}}
            <div class="pd-card">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-semibold text-slate-800">Recent payments</h3>
                    <a href="{{ route('billing.export') }}" class="text-sm font-medium text-teal-700 hover:text-teal-800">
                        Export all →
                    </a>
                </div>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50"><tr>
                        <th class="pd-th">When</th><th class="pd-th">Customer</th><th class="pd-th">Plan</th>
                        <th class="pd-th">Amount</th><th class="pd-th">Status</th><th class="pd-th">Reference</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($recentPayments as $p)
                            <tr>
                                <td class="px-6 py-2.5 text-slate-500 whitespace-nowrap">{{ $p->created_at->format('j M Y') }}</td>
                                <td class="px-6 py-2.5 text-slate-700">{{ $p->customer_email ?? '—' }}</td>
                                <td class="px-6 py-2.5 text-slate-600">{{ $p->plan ?? '—' }}</td>
                                <td class="px-6 py-2.5 font-semibold text-slate-800 tabular-nums whitespace-nowrap">
                                    {{ strtoupper($p->currency ?? 'USD') }} {{ number_format(($p->amount_total ?? 0)/100, 2) }}
                                </td>
                                <td class="px-6 py-2.5">
                                    <span class="text-xs font-semibold rounded-full px-2.5 py-1 border
                                                 {{ $p->status === 'paid' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                                        {{ ucfirst($p->status) }}
                                    </span>
                                </td>
                                {{-- The Stripe reference, shown as a link only when we
                                     actually hold a hosted invoice URL. A dead link
                                     here would be worse than plain text. --}}
                                <td class="px-6 py-2.5 font-mono text-xs text-slate-400">
                                    @php $url = $p->meta['invoice_url'] ?? null; @endphp
                                    @if ($url)
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-teal-700 hover:text-teal-800">
                                            {{ Str::limit($p->reference ?? '', 18) }}
                                        </a>
                                    @else
                                        {{ $p->reference ? Str::limit($p->reference, 18) : '—' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-10 text-center text-slate-500">
                                No payments yet.
                                <span class="block text-xs text-slate-400 mt-1">Completed checkouts and renewals appear here.</span>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
