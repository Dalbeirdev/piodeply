<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Coupons') }}</h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif

            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500">{{ $totalRedemptions }} total redemptions</p>
                <button type="button" wire:click="create" class="px-4 py-2 bg-teal-700 text-white rounded-lg text-sm font-semibold hover:bg-teal-800">+ New coupon</button>
            </div>

            {{-- Create / edit form --}}
            @if ($showForm)
                <form wire:submit="save" class="pd-card p-6 space-y-4">
                    <h3 class="font-semibold text-slate-800">{{ $editingId ? 'Edit coupon' : 'New coupon' }}</h3>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <x-label value="Code" />
                            <x-input type="text" class="mt-1 block w-full uppercase font-mono" wire:model="code" placeholder="LAUNCH20" />
                            <x-input-error for="code" class="mt-1" />
                        </div>
                        <div>
                            <x-label value="Name" />
                            <x-input type="text" class="mt-1 block w-full" wire:model="name" placeholder="Launch promo" />
                            <x-input-error for="name" class="mt-1" />
                        </div>
                        <div>
                            <x-label value="Type" />
                            <select wire:model.live="type" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                <option value="percent">Percentage off</option>
                                <option value="fixed">Fixed amount off (cents)</option>
                                <option value="trial_days">Extra trial days</option>
                            </select>
                        </div>
                        <div>
                            <x-label value="{{ $type === 'percent' ? 'Percent (1–100)' : ($type === 'fixed' ? 'Amount off (cents)' : 'Extra trial days') }}" />
                            <x-input type="number" min="1" class="mt-1 block w-full" wire:model="value" />
                            <x-input-error for="value" class="mt-1" />
                        </div>
                        <div>
                            <x-label value="Duration" />
                            <select wire:model.live="duration" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                <option value="once">One time</option>
                                <option value="repeating">Repeating (months)</option>
                                <option value="forever">Forever (lifetime)</option>
                            </select>
                        </div>
                        @if ($duration === 'repeating')
                            <div>
                                <x-label value="Duration in months" />
                                <x-input type="number" min="1" max="36" class="mt-1 block w-full" wire:model="durationInMonths" />
                            </div>
                        @endif
                        <div>
                            <x-label value="Restrict to plan (optional)" />
                            <select wire:model="planId" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                <option value="">Any plan</option>
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-label value="Expires on (optional)" />
                            <x-input type="date" class="mt-1 block w-full" wire:model="redeemBy" />
                        </div>
                        <div>
                            <x-label value="Max total redemptions (optional)" />
                            <x-input type="number" min="1" class="mt-1 block w-full" wire:model="maxRedemptions" />
                        </div>
                        <div>
                            <x-label value="Max per customer (optional)" />
                            <x-input type="number" min="1" class="mt-1 block w-full" wire:model="maxPerCustomer" />
                        </div>
                    </div>
                    <div class="flex gap-6">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><x-checkbox wire:model="autoApply" /> Auto-apply</label>
                        <label class="flex items-center gap-2 text-sm text-slate-700"><x-checkbox wire:model="isActive" /> Active</label>
                    </div>
                    <div class="flex justify-end gap-2 border-t pt-4">
                        <button type="button" wire:click="$set('showForm', false)" class="px-4 py-2 text-sm font-semibold text-slate-500">Cancel</button>
                        <x-button>{{ $editingId ? 'Update' : 'Create' }} coupon</x-button>
                    </div>
                </form>
            @endif

            {{-- List --}}
            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50"><tr>
                        <th class="pd-th">Code</th><th class="pd-th">Discount</th><th class="pd-th">Plan</th>
                        <th class="pd-th">Redeemed</th><th class="pd-th">Expires</th><th class="pd-th">Active</th><th class="px-6 py-3"></th>
                    </tr></thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($coupons as $coupon)
                            <tr>
                                <td class="px-6 py-3 font-mono text-sm text-slate-800">{{ $coupon->code }}</td>
                                <td class="px-6 py-3 text-sm text-slate-600">{{ $coupon->label() }} <span class="text-xs text-slate-400">/ {{ $coupon->duration }}</span></td>
                                <td class="px-6 py-3 text-sm text-slate-500">{{ $coupon->plan?->name ?? 'Any' }}</td>
                                <td class="px-6 py-3 text-sm text-slate-600">{{ $coupon->times_redeemed }}@if ($coupon->max_redemptions) / {{ $coupon->max_redemptions }}@endif</td>
                                <td class="px-6 py-3 text-sm {{ $coupon->isExpired() ? 'text-red-500' : 'text-slate-500' }}">{{ $coupon->redeem_by?->toFormattedDateString() ?? '—' }}</td>
                                <td class="px-6 py-3">
                                    <button type="button" wire:click="toggleActive({{ $coupon->id }})"
                                        class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $coupon->is_active ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                                        {{ $coupon->is_active ? 'Active' : 'Off' }}
                                    </button>
                                </td>
                                <td class="px-6 py-3 text-right space-x-2 whitespace-nowrap">
                                    <button type="button" wire:click="edit({{ $coupon->id }})" class="text-xs font-semibold text-teal-700 hover:text-teal-900">Edit</button>
                                    <button type="button" wire:click="delete({{ $coupon->id }})" wire:confirm="Delete coupon {{ $coupon->code }}?" class="text-xs font-semibold text-red-600 hover:text-red-800">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No coupons yet.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $coupons->links() }}
        </div>
    </div>
</div>
