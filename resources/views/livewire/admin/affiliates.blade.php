<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Affiliates') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif

            <div class="flex items-center justify-between">
                <a href="{{ route('affiliates.export') }}" class="text-sm pd-link">Export commissions (CSV)</a>
                <button type="button" wire:click="create" class="px-4 py-2 bg-teal-700 text-white rounded-lg text-sm font-semibold hover:bg-teal-800">+ New affiliate</button>
            </div>

            @if ($showForm)
                <form wire:submit="save" class="pd-card p-6 space-y-4">
                    <h3 class="font-semibold text-slate-800">{{ $editingId ? 'Edit affiliate' : 'New affiliate' }}</h3>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><x-label value="Name" /><x-input type="text" class="mt-1 block w-full" wire:model="name" /><x-input-error for="name" class="mt-1" /></div>
                        <div><x-label value="Email" /><x-input type="email" class="mt-1 block w-full" wire:model="email" /><x-input-error for="email" class="mt-1" /></div>
                        <div><x-label value="Referral code" /><x-input type="text" class="mt-1 block w-full font-mono lowercase" wire:model="code" placeholder="john" /><x-input-error for="code" class="mt-1" /></div>
                        <div>
                            <x-label value="Commission" />
                            <div class="flex gap-2 mt-1">
                                <select wire:model.live="commissionType" class="rounded-lg border-slate-300 text-sm">
                                    <option value="percentage">Percent</option>
                                    <option value="fixed">Fixed (cents)</option>
                                </select>
                                <x-input type="number" min="1" class="block w-32" wire:model="commissionRate" />
                            </div>
                            <x-input-error for="commissionRate" class="mt-1" />
                        </div>
                    </div>
                    <div class="flex gap-6">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><x-checkbox wire:model="recurring" /> Recurring (pay every invoice)</label>
                        <div>
                            <select wire:model="status" class="rounded-lg border-slate-300 text-sm">
                                <option value="approved">Approved</option>
                                <option value="pending">Pending</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t pt-4">
                        <button type="button" wire:click="$set('showForm', false)" class="px-4 py-2 text-sm font-semibold text-slate-500">Cancel</button>
                        <x-button>{{ $editingId ? 'Update' : 'Create' }}</x-button>
                    </div>
                </form>
            @endif

            {{-- Affiliates --}}
            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50"><tr>
                        <th class="pd-th">Affiliate</th><th class="pd-th">Code / link</th><th class="pd-th">Rate</th>
                        <th class="pd-th">Clicks</th><th class="pd-th">Approved $</th><th class="pd-th">Available $</th>
                        <th class="pd-th">Status</th><th class="px-6 py-3"></th>
                    </tr></thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($affiliates as $a)
                            @php $s = $statsFor($a); @endphp
                            <tr>
                                <td class="px-6 py-3 text-sm"><div class="font-medium text-slate-800">{{ $a->name }}</div><div class="text-xs text-slate-400">{{ $a->email }}</div></td>
                                <td class="px-6 py-3 text-xs font-mono text-slate-500 select-all">{{ $a->referralUrl() }}</td>
                                <td class="px-6 py-3 text-sm text-slate-600">{{ $a->commission_type === 'fixed' ? '$'.number_format($a->commission_rate/100,2) : $a->commission_rate.'%' }} {{ $a->recurring ? '' : '(once)' }}</td>
                                <td class="px-6 py-3 text-sm text-slate-600">{{ $s['clicks'] }}</td>
                                <td class="px-6 py-3 text-sm text-slate-600">${{ number_format($s['approved_cents']/100,2) }}</td>
                                <td class="px-6 py-3 text-sm font-semibold text-emerald-700">${{ number_format($s['available_cents']/100,2) }}</td>
                                <td class="px-6 py-3">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border capitalize
                                        {{ $a->status==='approved' ? 'bg-green-50 text-green-700 border-green-200' : ($a->status==='rejected' ? 'bg-red-50 text-red-600 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200') }}">{{ $a->status }}</span>
                                </td>
                                <td class="px-6 py-3 text-right text-xs space-x-2 whitespace-nowrap">
                                    @if ($a->status !== 'approved')
                                        <button wire:click="setStatus({{ $a->id }},'approved')" class="font-semibold text-green-700 hover:underline">Approve</button>
                                    @else
                                        <button wire:click="setStatus({{ $a->id }},'rejected')" class="font-semibold text-red-600 hover:underline">Reject</button>
                                    @endif
                                    <button wire:click="select({{ $a->id }})" class="font-semibold text-teal-700 hover:underline">Detail</button>
                                    <button wire:click="edit({{ $a->id }})" class="font-semibold text-slate-500 hover:underline">Edit</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-6 py-8 text-center text-slate-500">No affiliates yet.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{-- Detail: commissions + payout --}}
            @if ($selected)
                <div class="pd-card p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-slate-800">{{ $selected->name }} — commissions</h3>
                        <button wire:click="payout({{ $selected->id }})" wire:confirm="Record a payout of the full available balance?"
                            class="px-3 py-2 text-sm font-semibold text-white bg-teal-700 rounded-lg hover:bg-teal-800">Pay out balance</button>
                    </div>
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50"><tr><th class="pd-th">When</th><th class="pd-th">Base</th><th class="pd-th">Commission</th><th class="pd-th">Status</th><th class="px-6 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($commissions as $c)
                                <tr>
                                    <td class="px-6 py-2 text-slate-500">{{ $c->created_at->toFormattedDateString() }}</td>
                                    <td class="px-6 py-2 text-slate-600">${{ number_format($c->base_amount_cents/100,2) }}</td>
                                    <td class="px-6 py-2 font-semibold text-slate-800">${{ number_format($c->amount_cents/100,2) }}</td>
                                    <td class="px-6 py-2 capitalize">{{ $c->status }}</td>
                                    <td class="px-6 py-2 text-right space-x-2">
                                        @if ($c->status === 'pending')
                                            <button wire:click="approveCommission({{ $c->id }})" class="text-xs font-semibold text-green-700 hover:underline">Approve</button>
                                            <button wire:click="rejectCommission({{ $c->id }})" class="text-xs font-semibold text-red-600 hover:underline">Reject</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-6 text-center text-slate-500">No commissions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>

                    @if ($withdrawals->isNotEmpty())
                        <h4 class="text-sm font-semibold text-slate-700 mt-2">Payouts</h4>
                        <ul class="text-sm text-slate-600 space-y-1">
                            @foreach ($withdrawals as $w)
                                <li>${{ number_format($w->amount_cents/100,2) }} — {{ $w->status }} {{ $w->paid_at?->toFormattedDateString() }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
