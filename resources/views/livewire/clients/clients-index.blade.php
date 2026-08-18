<div>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Clients') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">Manage and monitor all your clients in one place.</p>
            </div>
            @can('create', \App\Models\Client::class)
                <a href="{{ route('clients.create') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 rounded-lg font-semibold text-sm text-white hover:bg-teal-800 shrink-0">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New client
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @unless ($isTenant)
                @php
                    $cards = [
                        ['label' => 'Total clients', 'value' => $stats['clients'],  'sub' => 'All registered clients',   'tone' => 'teal',   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>'],
                        ['label' => 'Active',        'value' => $stats['active'],   'sub' => 'Currently enabled',        'tone' => 'green',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                        ['label' => 'On trial',      'value' => $stats['trialing'], 'sub' => 'Not yet charged',          'tone' => 'amber',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                        ['label' => 'Paying',        'value' => $stats['paying'],   'sub' => 'Live subscriptions',       'tone' => 'teal',   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>'],
                        ['label' => project_terms(), 'value' => $stats['sites'],    'sub' => 'Across all clients',       'tone' => 'slate',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0a8.949 8.949 0 0 0 4.951-1.488A3.987 3.987 0 0 0 13 16h-2a3.987 3.987 0 0 0-3.951 3.512A8.949 8.949 0 0 0 12 21Zm3-11.25a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>'],
                    ];
                    $tones = [
                        'teal'  => 'bg-teal-50 text-teal-700 border-teal-100',
                        'green' => 'bg-green-50 text-green-700 border-green-100',
                        'amber' => 'bg-amber-50 text-amber-700 border-amber-100',
                        'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
                    ];
                @endphp
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                    @foreach ($cards as $card)
                        <div class="pd-card p-4">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $tones[$card['tone']] }}">
                                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $card['icon'] !!}</svg>
                                </span>
                                <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                            </div>
                            <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                            <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endunless

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif
            @if ($importSummary !== '')
                <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700" role="status">
                    {{ $importSummary }}
                </div>
            @endif

            <div class="pd-card p-4 flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search company, email, city…" aria-label="Search clients"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-lg shadow-sm w-full sm:w-72">
                <select wire:model.live="status" aria-label="Filter by status"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                    @endforeach
                </select>
                @unless ($isTenant)
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" wire:model.live="showTrashed" class="rounded border-slate-300">
                        Show deleted
                    </label>
                @endunless
                <span class="flex-1"></span>
                @unless ($isTenant)
                    <button wire:click="export" class="text-sm pd-action">Export CSV</button>
                @endunless
                @can('create', \App\Models\Client::class)
                    <form wire:submit="import" class="flex items-center gap-2">
                        <input type="file" wire:model="importFile" accept=".csv,.txt" aria-label="Import CSV"
                               class="text-sm text-slate-600 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:bg-slate-100 file:text-sm">
                        <button type="submit" class="text-sm pd-action"
                                @disabled(! $importFile)>Import</button>
                    </form>
                @endcan
            </div>
            @error('importFile') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Company</th>
                            <th class="pd-th">Email</th>
                            <th class="pd-th">Primary contact</th>
                            <th class="pd-th">Timezone</th>
                            <th class="pd-th">Status</th>
                            <th class="pd-th">Billing</th>
                            <th class="pd-th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($clients as $client)
                            <tr @class(['opacity-60' => $client->trashed()])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        @if ($client->logoUrl())
                                            <img src="{{ $client->logoUrl() }}" alt="" class="h-8 w-8 rounded object-cover">
                                        @else
                                            <span class="h-8 w-8 rounded bg-slate-100 grid place-content-center text-xs font-bold text-slate-500">
                                                {{ strtoupper(substr($client->company_name, 0, 2)) }}
                                            </span>
                                        @endif
                                        <span class="font-medium text-slate-900">{{ $client->company_name }}</span>
                                        @if ($client->trashed())
                                            <span class="text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $client->email }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">
                                    {{ $client->primaryContact?->name ?? '—' }}
                                    <span class="text-xs text-slate-400">({{ $client->contacts_count }})</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $client->timezone }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span @class([
                                        'text-xs font-semibold rounded-full px-2 py-0.5 border',
                                        'bg-green-50 text-green-700 border-green-200' => $client->status === \App\Enums\ClientStatus::Active,
                                        'bg-slate-100 text-slate-600 border-slate-200' => $client->status === \App\Enums\ClientStatus::Inactive,
                                        'bg-yellow-50 text-yellow-700 border-yellow-200' => $client->status === \App\Enums\ClientStatus::Suspended,
                                    ])>{{ $client->status->label() }}</span>
                                </td>

                                {{-- Billing stands on its own: an account can be
                                     active in the portal while its subscription
                                     is past due, and merging the two hid that. --}}
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if ($client->subscription_status !== null)
                                        @php
                                            $subTitle = 'Subscription: '.$client->subscription_status
                                                .($client->subscription_cents ? ' · $'.number_format($client->subscription_cents / 100, 2).'/mo' : '')
                                                .($client->subscription_period_end ? ' · renews '.$client->subscription_period_end->format('j M') : '');
                                        @endphp
                                        <span @class([
                                            'text-xs font-semibold rounded-full px-2.5 py-1 border capitalize',
                                            'bg-green-50 text-green-700 border-green-200' => $client->subscription_status === 'active',
                                            'bg-amber-50 text-amber-700 border-amber-200' => in_array($client->subscription_status, ['trialing', 'past_due', 'unpaid'], true),
                                            'bg-slate-100 text-slate-600 border-slate-200' => ! in_array($client->subscription_status, ['active', 'trialing', 'past_due', 'unpaid'], true),
                                        ]) title="{{ $subTitle }}">
                                            {{ str($client->subscription_status)->replace('_', ' ') }}
                                        </span>
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    @if ($client->trashed())
                                        @can('restore', $client)
                                            <x-icon-button icon="restore" label="Restore" wire:click="restore({{ $client->id }})" />
                                        @endcan
                                    @else
                                        @can('reports.view')
                                            <a href="{{ route('clients.compliance-report', $client) }}"
                                               class="inline-flex items-center text-xs font-semibold text-teal-700 hover:text-teal-600 mr-1"
                                               title="Download this client's branded compliance PDF">
                                                PDF
                                            </a>
                                        @endcan
                                        @can('update', $client)
                                            <label class="inline-flex items-center gap-1 text-xs text-slate-500 mr-1 select-none"
                                                   title="Email the compliance PDF to this client's portal users on the 1st of each month">
                                                <input type="checkbox" @checked($client->monthly_report)
                                                       wire:click="toggleMonthlyReport({{ $client->id }})"
                                                       class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                                Monthly
                                            </label>
                                            <x-icon-button icon="edit" label="Edit" :href="route('clients.edit', $client)" />
                                        @endcan
                                        @can('delete', $client)
                                            <x-icon-button icon="delete" variant="danger" label="Delete"
                                                           wire:click="delete({{ $client->id }})"
                                                           wire:confirm="Delete client “{{ $client->company_name }}”? It can be restored later." />
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-10 text-center">
                                <p class="text-slate-500">No clients found.</p>
                                <p class="text-xs text-slate-400 mt-1">Try a different search, or clear the status filter.</p>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{-- Say where you are in the list, not just how to move through it. --}}
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <p class="text-sm text-slate-500">
                    @if ($clients->total() > 0)
                        Showing {{ $clients->firstItem() }} to {{ $clients->lastItem() }}
                        of {{ number_format($clients->total()) }} {{ Str::plural('client', $clients->total()) }}
                    @else
                        No clients to show
                    @endif
                </p>
                <div>{{ $clients->links() }}</div>
            </div>
        </div>
    </div>
</div>
