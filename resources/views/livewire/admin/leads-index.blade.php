<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Enquiries') }}
            @if ($unreadCount > 0)
                <span class="ml-2 inline-flex items-center rounded-full bg-teal-600 text-white text-xs font-bold px-2 py-0.5">
                    {{ $unreadCount }} unread
                </span>
            @elseif ($openCount > 0)
                <span class="ml-1 text-sm font-normal text-slate-400">{{ $openCount }} open</span>
            @endif
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search name, email or company…" aria-label="Search enquiries"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-72">
                <select wire:model.live="type" aria-label="Filter by type"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All enquiries</option>
                    <option value="access_request">Access requests</option>
                    <option value="contact">Contact messages</option>
                </select>
                <label class="flex items-center gap-2 text-sm text-slate-600 select-none">
                    <input type="checkbox" wire:model.live="openOnly"
                           class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Open only
                </label>
            </div>

            <div class="pd-card divide-y divide-slate-100">
                @forelse ($leads as $lead)
                    @php $open = $viewingId === $lead->id; @endphp
                    <div class="{{ $lead->handled_at ? 'opacity-70' : '' }} {{ $lead->isUnread() ? 'border-l-2 border-teal-500' : 'border-l-2 border-transparent' }}">

                        {{-- The row. Clicking it opens the full enquiry and marks it read. --}}
                        <div class="flex items-center gap-4 px-6 py-4 cursor-pointer hover:bg-slate-50/70 transition-colors"
                             wire:click="view({{ $lead->id }})">
                            <span class="w-2 h-2 rounded-full shrink-0 {{ $lead->isUnread() ? 'bg-teal-500' : 'bg-transparent' }}"></span>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm {{ $lead->isUnread() ? 'font-bold text-slate-900' : 'font-medium text-slate-700' }}">{{ $lead->name }}</span>
                                    <span class="pd-badge {{ $lead->type === 'access_request' ? 'pd-badge-teal' : 'pd-badge-slate' }}">
                                        {{ $lead->type === 'access_request' ? 'Access request' : 'Contact' }}
                                    </span>
                                    @if ($lead->handled_at)
                                        <span class="text-xs text-green-600">✓ handled</span>
                                    @endif
                                </div>
                                <p class="text-sm text-slate-500 truncate mt-0.5">
                                    <span class="text-slate-400">{{ $lead->company ?: $lead->email }}</span>
                                    @if ($lead->message) — {{ $lead->message }} @endif
                                </p>
                            </div>

                            <span class="text-xs text-slate-400 whitespace-nowrap shrink-0" title="{{ $lead->created_at }}">
                                {{ $lead->created_at->diffForHumans() }}
                            </span>
                            <svg class="w-4 h-4 text-slate-300 shrink-0 transition-transform {{ $open ? 'rotate-90' : '' }}"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </div>

                        {{-- Expanded detail. --}}
                        @if ($open)
                            <div class="px-6 pb-5 pt-1 bg-slate-50/50">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm mb-4">
                                    <div>
                                        <div class="text-xs text-slate-400">Email</div>
                                        <a href="mailto:{{ $lead->email }}" class="pd-link">{{ $lead->email }}</a>
                                    </div>
                                    <div>
                                        <div class="text-xs text-slate-400">Company</div>
                                        <div class="text-slate-700">{{ $lead->company ?: '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-slate-400">Fleet size</div>
                                        <div class="text-slate-700">{{ $lead->fleet_size ?: '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-slate-400">Submitted</div>
                                        <div class="text-slate-700" title="IP {{ $lead->ip ?? 'unknown' }}">{{ $lead->created_at->format('j M Y, H:i') }}</div>
                                    </div>
                                </div>

                                @if ($lead->message)
                                    <div class="rounded-lg bg-white border border-slate-200 p-4 text-sm text-slate-700 whitespace-pre-wrap mb-4">{{ $lead->message }}</div>
                                @endif

                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ $lead->replyMailto() }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                                        ✉ Reply
                                    </a>
                                    <x-secondary-button type="button" wire:click="markHandled({{ $lead->id }})">
                                        {{ $lead->handled_at ? 'Reopen' : 'Mark handled' }}
                                    </x-secondary-button>
                                    <x-secondary-button type="button" wire:click="toggleRead({{ $lead->id }})">
                                        {{ $lead->isUnread() ? 'Mark read' : 'Mark unread' }}
                                    </x-secondary-button>
                                    <button type="button" wire:click="delete({{ $lead->id }})"
                                            wire:confirm="Delete this enquiry from {{ $lead->name }}? This cannot be undone."
                                            class="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-semibold text-red-600 hover:bg-red-50">
                                        Delete
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="px-6 py-10 text-center text-slate-500">
                        @if ($openOnly && $search === '' && $type === '')
                            Nothing waiting — every enquiry has been handled.
                        @else
                            No enquiries match those filters.
                        @endif
                    </div>
                @endforelse
            </div>

            {{ $leads->links() }}

            <p class="text-xs text-slate-400">
                Every submission from the website is recorded here whether or not an email went out.
                To be alerted as they arrive, add an email or webhook channel under
                <a href="{{ route('admin.notifications') }}" class="pd-link">Notifications</a>
                subscribed to “Contact / access-request submitted on the website”.
            </p>
        </div>
    </div>
</div>
