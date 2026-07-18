@php $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2); @endphp
<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Billing Overview') }}</h2>
            <a href="{{ route('billing.export') }}" class="text-sm pd-link">Export payments (CSV)</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Headline numbers --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="pd-card p-5">
                    <p class="text-2xl font-bold text-slate-900">{{ $money($m['mrr_cents']) }}</p>
                    <p class="text-xs font-semibold text-slate-500">MRR</p>
                </div>
                <div class="pd-card p-5">
                    <p class="text-2xl font-bold text-slate-900">{{ $money($m['arr_cents']) }}</p>
                    <p class="text-xs font-semibold text-slate-500">ARR</p>
                </div>
                <div class="pd-card p-5">
                    <p class="text-2xl font-bold text-slate-900">{{ $money($m['revenue_cents']) }}</p>
                    <p class="text-xs font-semibold text-slate-500">Total revenue</p>
                </div>
                <div class="pd-card p-5">
                    <p class="text-2xl font-bold text-slate-900">{{ $money($m['ltv_cents']) }}</p>
                    <p class="text-xs font-semibold text-slate-500">Lifetime value</p>
                </div>
            </div>

            {{-- Revenue graph --}}
            <div class="pd-card p-6">
                <h3 class="text-sm font-semibold text-slate-700 mb-4">Revenue — last 12 months</h3>
                <div class="flex items-end gap-2 h-40">
                    @foreach ($series as $point)
                        @php $h = (int) round(($point['cents'] / $seriesMax) * 100); @endphp
                        <div class="flex-1 flex flex-col items-center justify-end gap-1 group">
                            <div class="w-full rounded-t bg-teal-500/80 group-hover:bg-teal-600 transition-colors relative"
                                 style="height: {{ max($h, 1) }}%" title="{{ $money($point['cents']) }}"></div>
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

            {{-- Recent payments --}}
            <div class="pd-card">
                <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Recent payments</h3></div>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50"><tr><th class="pd-th">When</th><th class="pd-th">Plan</th><th class="pd-th">Amount</th><th class="pd-th">Status</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($recentPayments as $p)
                            <tr>
                                <td class="px-6 py-2 text-slate-500">{{ $p->created_at->toFormattedDateString() }}</td>
                                <td class="px-6 py-2 text-slate-600">{{ $p->plan ?? '—' }}</td>
                                <td class="px-6 py-2 font-semibold text-slate-800">{{ strtoupper($p->currency ?? 'USD') }} {{ number_format(($p->amount_total ?? 0)/100, 2) }}</td>
                                <td class="px-6 py-2 capitalize">{{ $p->status }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
