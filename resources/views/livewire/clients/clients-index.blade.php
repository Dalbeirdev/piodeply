<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Clients') }}</h2>
            @can('create', \App\Models\Client::class)
                <a href="{{ route('clients.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    + New Client
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
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

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search company, email, city…" aria-label="Search clients"
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-72">
                <select wire:model.live="status" aria-label="Filter by status"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-gray-300">
                    Show deleted
                </label>
                <span class="flex-1"></span>
                <button wire:click="export" class="text-sm font-semibold text-indigo-600 hover:underline">Export CSV</button>
                @can('create', \App\Models\Client::class)
                    <form wire:submit="import" class="flex items-center gap-2">
                        <input type="file" wire:model="importFile" accept=".csv,.txt" aria-label="Import CSV"
                               class="text-sm text-gray-600 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:bg-gray-100 file:text-sm">
                        <button type="submit" class="text-sm font-semibold text-indigo-600 hover:underline"
                                @disabled(! $importFile)>Import</button>
                    </form>
                @endcan
            </div>
            @error('importFile') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Primary contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timezone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($clients as $client)
                            <tr @class(['opacity-60' => $client->trashed()])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        @if ($client->logoUrl())
                                            <img src="{{ $client->logoUrl() }}" alt="" class="h-8 w-8 rounded object-cover">
                                        @else
                                            <span class="h-8 w-8 rounded bg-gray-100 grid place-content-center text-xs font-bold text-gray-500">
                                                {{ strtoupper(substr($client->company_name, 0, 2)) }}
                                            </span>
                                        @endif
                                        <span class="font-medium text-gray-900">{{ $client->company_name }}</span>
                                        @if ($client->trashed())
                                            <span class="text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">{{ $client->email }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">
                                    {{ $client->primaryContact?->name ?? '—' }}
                                    <span class="text-xs text-gray-400">({{ $client->contacts_count }})</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">{{ $client->timezone }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span @class([
                                        'text-xs font-semibold rounded-full px-2 py-0.5 border',
                                        'bg-green-50 text-green-700 border-green-200' => $client->status === \App\Enums\ClientStatus::Active,
                                        'bg-gray-100 text-gray-600 border-gray-200' => $client->status === \App\Enums\ClientStatus::Inactive,
                                        'bg-yellow-50 text-yellow-700 border-yellow-200' => $client->status === \App\Enums\ClientStatus::Suspended,
                                    ])>{{ $client->status->label() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-2">
                                    @if ($client->trashed())
                                        @can('restore', $client)
                                            <button wire:click="restore({{ $client->id }})"
                                                    class="font-semibold text-indigo-600 hover:underline">Restore</button>
                                        @endcan
                                    @else
                                        @can('update', $client)
                                            <a href="{{ route('clients.edit', $client) }}"
                                               class="font-semibold text-indigo-600 hover:underline">Edit</a>
                                        @endcan
                                        @can('delete', $client)
                                            <button wire:click="delete({{ $client->id }})"
                                                    wire:confirm="Delete client “{{ $client->company_name }}”? It can be restored later."
                                                    class="font-semibold text-red-600 hover:underline">Delete</button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No clients found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $clients->links() }}
        </div>
    </div>
</div>
