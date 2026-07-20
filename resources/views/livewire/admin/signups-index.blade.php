<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-900 leading-tight">
            Signups
            @if ($openCount > 0)
                <span class="ml-2 align-middle pd-badge pd-badge-amber">{{ $openCount }} awaiting decision</span>
            @endif
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search company, contact, email…"
                       class="w-72 text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="openOnly" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Open only
                </label>
            </div>

            <div class="pd-card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-400 uppercase tracking-wide">
                            <th class="px-6 py-3">Company / contact</th>
                            <th class="px-6 py-3">Fleet</th>
                            <th class="px-6 py-3">Monthly</th>
                            <th class="px-6 py-3">Payment</th>
                            <th class="px-6 py-3">Applied</th>
                            <th class="px-6 py-3 text-right">Decision</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($signups as $signup)
                            <tr>
                                <td class="px-6 py-3">
                                    <p class="font-semibold text-slate-800">{{ $signup->company_name }}</p>
                                    <p class="text-xs text-slate-500">{{ $signup->contact_name }} · {{ $signup->email }}
                                        @if ($signup->phone) · {{ $signup->phone }} @endif
                                        @if ($signup->country) · {{ $signup->country }} @endif
                                    </p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">{{ number_format($signup->machines) }} machines</td>
                                <td class="px-6 py-3 whitespace-nowrap font-medium">{{ $signup->monthlyLabel() }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @switch($signup->status)
                                        @case(\App\Models\Signup::STATUS_PAID)
                                            <span class="pd-badge pd-badge-green" title="Stripe secured this checkout ({{ $signup->paid_at?->format('Y-m-d H:i') }}) — card verified; on a trial the first charge lands when it ends"><span class="pd-dot"></span>Payment secured (Stripe)</span>
                                            @break
                                        @case(\App\Models\Signup::STATUS_PENDING_PAYMENT)
                                            <span class="pd-badge pd-badge-amber"><span class="pd-dot"></span>Awaiting Stripe payment</span>
                                            @break
                                        @case(\App\Models\Signup::STATUS_AWAITING_VERIFICATION)
                                            @if ($signup->payment_method === 'invoice')
                                                <span class="pd-badge pd-badge-sky" title="The applicant chose to pay by invoice — send one, verify the money arrived, then approve"><span class="pd-dot"></span>Invoice requested</span>
                                            @else
                                                <span class="pd-badge pd-badge-sky" title="No online payment — verify the invoice/transfer before approving"><span class="pd-dot"></span>Verify manually</span>
                                            @endif
                                            @break
                                        @case(\App\Models\Signup::STATUS_APPROVED)
                                            <span class="pd-badge pd-badge-green"><span class="pd-dot"></span>Approved {{ $signup->approved_at?->format('Y-m-d') }}</span>
                                            @break
                                        @default
                                            <span class="pd-badge pd-badge-slate" title="{{ $signup->rejection_reason }}"><span class="pd-dot"></span>Rejected</span>
                                    @endswitch
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-xs text-slate-500">{{ $signup->created_at->diffForHumans() }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap space-x-3">
                                    @if ($signup->isOpen())
                                        @if ($rejectingId === $signup->id)
                                            <div class="flex items-center gap-2 justify-end">
                                                <input type="text" wire:model="rejectionReason" placeholder="Reason (optional)"
                                                       class="w-44 text-xs border-slate-300 rounded-md">
                                                <button type="button" wire:click="confirmReject" class="text-xs text-rose-600 font-semibold">Confirm</button>
                                                <button type="button" wire:click="cancelReject" class="text-xs pd-action">Cancel</button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="approve({{ $signup->id }})"
                                                wire:confirm="Approve {{ $signup->company_name }}? This creates their client account and login, and emails {{ $signup->email }} that they can sign in.{{ $signup->status !== \App\Models\Signup::STATUS_PAID ? ' Payment has NOT been confirmed by Stripe — only approve if you have verified it yourself.' : '' }}"
                                                class="text-sm font-semibold {{ $signup->status === \App\Models\Signup::STATUS_PAID ? 'text-teal-700 hover:text-teal-800' : 'text-amber-600 hover:text-amber-700' }}">
                                                Approve
                                            </button>
                                            <button type="button" wire:click="startReject({{ $signup->id }})"
                                                    class="text-sm text-rose-600 hover:text-rose-700">Reject</button>
                                        @endif
                                    @elseif ($signup->client_id)
                                        <a href="{{ route('clients.edit', $signup->client_id) }}" class="text-sm pd-action">View client</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No signups yet. Applications from the pricing page land here.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $signups->links() }}
        </div>
    </div>
</div>
