<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Enquiries') }}
            @if ($openCount > 0)
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

            <div class="pd-card">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="pd-th">From</th>
                                <th class="pd-th">Type</th>
                                <th class="pd-th">Fleet</th>
                                <th class="pd-th">Message</th>
                                <th class="pd-th">When</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-100">
                            @forelse ($leads as $lead)
                                <tr class="{{ $lead->handled_at ? 'opacity-55' : '' }}">
                                    <td class="px-6 py-3">
                                        <div class="text-sm font-medium text-slate-800">{{ $lead->name }}</div>
                                        <a href="mailto:{{ $lead->email }}" class="pd-link text-xs">{{ $lead->email }}</a>
                                        @if ($lead->company)
                                            <div class="text-xs text-slate-400">{{ $lead->company }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="pd-badge {{ $lead->type === 'access_request' ? 'pd-badge-teal' : 'pd-badge-slate' }}">
                                            {{ $lead->type === 'access_request' ? 'Access request' : 'Contact' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $lead->fleet_size ?: '—' }}</td>
                                    <td class="px-6 py-3 text-sm text-slate-600 max-w-md">
                                        {{ $lead->message ?: '—' }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500"
                                        title="{{ $lead->created_at }}">
                                        {{ $lead->created_at->diffForHumans() }}
                                        @if ($lead->handled_at)
                                            <div class="text-xs text-green-600">handled {{ $lead->handled_at->diffForHumans() }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right">
                                        <button type="button" wire:click="markHandled({{ $lead->id }})"
                                                class="text-xs pd-link">
                                            {{ $lead->handled_at ? 'Reopen' : 'Mark handled' }}
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">
                                    @if ($openOnly && $search === '' && $type === '')
                                        Nothing waiting — every enquiry has been handled.
                                    @else
                                        No enquiries match those filters.
                                    @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
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
