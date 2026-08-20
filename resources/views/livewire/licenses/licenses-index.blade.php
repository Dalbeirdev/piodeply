<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-900 leading-tight">Software licenses</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-5">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
            @endif

            @php
                $licenseCards = [
                    ['label' => 'Total licenses', 'value' => $stats['total'], 'sub' => 'Tracked here', 'tone' => 'teal', 'filter' => null,
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                    ['label' => 'Expiring soon', 'value' => $stats['expiring'], 'sub' => 'Within 30 days', 'tone' => $stats['expiring'] > 0 ? 'amber' : 'slate', 'filter' => 'expiring',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                    ['label' => 'Expired', 'value' => $stats['expired'], 'sub' => 'Renewal overdue', 'tone' => $stats['expired'] > 0 ? 'red' : 'slate', 'filter' => 'expired',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 17h.01"/>'],
                    ['label' => 'Seats full', 'value' => $stats['full'], 'sub' => 'No free seat left', 'tone' => $stats['full'] > 0 ? 'amber' : 'slate', 'filter' => 'full',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/>'],
                ];
                $licenseTones = [
                    'teal'  => 'bg-teal-50 text-teal-700 border-teal-100',
                    'amber' => 'bg-amber-50 text-amber-700 border-amber-100',
                    'red'   => 'bg-red-50 text-red-700 border-red-100',
                    'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
                ];
            @endphp
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ($licenseCards as $card)
                    @php $tag = $card['filter'] === null ? 'div' : 'button'; @endphp
                    <{{ $tag }}
                        @if ($card['filter'] !== null)
                            type="button" wire:click="$set('statusFilter', '{{ $statusFilter === $card['filter'] ? '' : $card['filter'] }}')"
                        @endif
                        @class([
                            'pd-card p-4 block text-left w-full',
                            'hover:border-teal-200 transition-colors cursor-pointer' => $card['filter'] !== null,
                            'ring-2 ring-teal-500' => $card['filter'] !== null && $statusFilter === $card['filter'],
                        ])>
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $licenseTones[$card['tone']] }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $card['icon'] !!}</svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                        <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                    </{{ $tag }}>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or vendor…"
                       class="block w-64 text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                @if ($isStaff)
                    <select wire:model.live="clientFilter" class="block w-56 text-sm border-slate-300 rounded-md shadow-sm">
                        <option value="">All clients</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($statusFilter !== '')
                    <button type="button" wire:click="$set('statusFilter', '')" class="text-xs pd-action">Clear status filter</button>
                @endif
            </div>

            @if ($canManage)
                <div class="pd-card p-5 space-y-3">
                    <h3 class="text-sm font-semibold text-slate-800">{{ $editingId ? 'Edit license' : 'Add a license' }}</h3>
                    <div class="grid sm:grid-cols-3 gap-2">
                        <input type="text" wire:model="name" placeholder="Name (e.g. Acrobat Pro DC)"
                               class="block w-full text-sm border-slate-300 rounded-md shadow-sm">
                        <input type="text" wire:model="vendor" placeholder="Vendor (optional)"
                               class="block w-full text-sm border-slate-300 rounded-md shadow-sm">
                        <select wire:model="packageId" class="block w-full text-sm border-slate-300 rounded-md shadow-sm">
                            <option value="">Linked package (optional)</option>
                            @foreach ($packages as $package)
                                <option value="{{ $package->id }}">{{ $package->name }}</option>
                            @endforeach
                        </select>
                        @if ($isStaff && ! $editingId)
                            <select wire:model="formClientId" class="block w-full text-sm border-slate-300 rounded-md shadow-sm">
                                <option value="">Owning client…</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                                @endforeach
                            </select>
                        @endif
                        <input type="text" wire:model="licenseKey" autocomplete="off"
                               placeholder="{{ $editingId ? 'License key (blank = keep stored)' : 'License key' }}"
                               class="block w-full text-sm font-mono border-slate-300 rounded-md shadow-sm">
                        <input type="number" wire:model="seats" min="1" placeholder="Seats (blank = unlimited)"
                               class="block w-full text-sm border-slate-300 rounded-md shadow-sm">
                        <input type="date" wire:model="expiresAt"
                               class="block w-full text-sm border-slate-300 rounded-md shadow-sm">
                        <input type="text" wire:model="notes" placeholder="Notes (optional)"
                               class="block w-full text-sm border-slate-300 rounded-md shadow-sm sm:col-span-2">
                    </div>
                    @foreach (['name', 'formClientId', 'seats', 'expiresAt'] as $field)
                        @error($field)<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @endforeach
                    <div class="flex gap-2">
                        <button type="button" wire:click="save"
                                class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                            {{ $editingId ? 'Save changes' : 'Add license' }}
                        </button>
                        @if ($editingId)
                            <button type="button" wire:click="$set('editingId', null)" class="text-sm pd-action">Cancel</button>
                        @endif
                    </div>
                    <p class="text-xs text-slate-400">
                        Keys are encrypted at rest. Only your organisation can ever reveal a key — platform staff
                        see that a license exists (and its last 4 characters), never its value.
                    </p>
                </div>
            @endif

            @forelse ($licenses as $license)
                <div class="pd-card p-5 space-y-3" wire:key="lic-{{ $license->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800">
                                {{ $license->name }}
                                @if ($license->vendor)<span class="text-slate-400 font-normal">· {{ $license->vendor }}</span>@endif
                                @if ($isStaff)<span class="ml-1 pd-badge pd-badge-slate">{{ $license->client?->company_name }}</span>@endif
                                @if ($license->isExpired())
                                    <span class="ml-1 pd-badge pd-badge-amber">Expired {{ $license->expires_at->format('j M Y') }}</span>
                                @elseif ($license->expiresSoon())
                                    <span class="ml-1 pd-badge pd-badge-amber">Expires {{ $license->expires_at->format('j M Y') }}</span>
                                @elseif ($license->expires_at)
                                    <span class="ml-1 text-xs text-slate-400">until {{ $license->expires_at->format('j M Y') }}</span>
                                @endif
                            </h3>
                            <p class="text-xs text-slate-500 mt-0.5">
                                Seats: <b>{{ $license->assignments->count() }}{{ $license->seats ? ' / '.$license->seats : ' (unlimited)' }}</b>
                                @if ($license->package) · linked to {{ $license->package->name }} @endif
                                @if ($license->notes) · {{ $license->notes }} @endif
                            </p>
                            <p class="text-xs font-mono text-slate-500 mt-1">
                                @if ($revealedId === $license->id)
                                    {{ $revealedKey ?? '(no key stored)' }}
                                    <button type="button" wire:click="hideKey" class="ml-2 pd-action">Hide</button>
                                @else
                                    Key: ••••{{ $license->key_last4 ?? '—' }}
                                    @if (! $isStaff)
                                        <button type="button" wire:click="reveal({{ $license->id }})" class="ml-2 pd-action">Reveal</button>
                                    @endif
                                @endif
                            </p>
                        </div>
                        @if ($canManage)
                            <div class="flex gap-3 shrink-0">
                                <button type="button" wire:click="edit({{ $license->id }})" class="text-sm pd-action">Edit</button>
                                <button type="button" wire:click="delete({{ $license->id }})"
                                        wire:confirm="Delete license “{{ $license->name }}” and its assignments?"
                                        class="text-sm text-rose-600 hover:text-rose-700 font-medium">Delete</button>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($license->assignments as $assignment)
                            <span class="text-xs bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5">
                                {{ $assignment->computer?->hostname ?? '—' }}
                                @if ($canManage)
                                    <button type="button" wire:click="unassign({{ $license->id }}, {{ $assignment->computer_id }})"
                                            class="ml-1 text-slate-400 hover:text-rose-600" title="Unassign">×</button>
                                @endif
                            </span>
                        @endforeach
                        @if ($canManage && $license->hasFreeSeat())
                            @php $fleet = $computersByClient[$license->client_id] ?? collect(); @endphp
                            @if ($fleet->isNotEmpty())
                                <select wire:model="assignComputer.{{ $license->id }}"
                                        class="text-xs border-slate-300 rounded-md py-1">
                                    <option value="">Assign to machine…</option>
                                    @foreach ($fleet as $computer)
                                        <option value="{{ $computer->id }}">{{ $computer->hostname }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="assign({{ $license->id }})" class="text-xs pd-action">Assign</button>
                            @endif
                        @elseif ($canManage)
                            <span class="text-xs text-amber-600">All seats used</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="pd-card p-8 text-center text-sm text-slate-500">
                    @if ($statusFilter !== '' || $search !== '')
                        No licenses match this filter.
                    @else
                        No licenses yet. Add your paid software licenses to track keys, seats and renewals in one place.
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</div>
