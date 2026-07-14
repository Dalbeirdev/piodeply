<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Packages') }}</h2>
            @can('create', \App\Models\Package::class)
                <a href="{{ route('packages.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    + New Package
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

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search name, vendor, package id…" aria-label="Search packages"
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-80">
                <select wire:model.live="categoryId" aria-label="Filter by category"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="installerType" aria-label="Filter by installer type"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All types</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="activeOnly" class="rounded border-gray-300">
                    Active only
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-gray-300">
                    Show deleted
                </label>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Package</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Latest version</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($packages as $package)
                            <tr @class(['opacity-60' => $package->trashed()])>
                                <td class="px-6 py-3">
                                    <a href="{{ route('packages.show', $package) }}"
                                       class="font-medium text-indigo-700 hover:underline">{{ $package->name }}</a>
                                    @if ($package->trashed())
                                        <span class="ml-1 text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                    @endif
                                    <p class="text-xs text-gray-500">
                                        {{ $package->vendor }}
                                        @if ($package->winget_id) · <code class="font-mono">{{ $package->winget_id }}</code> @endif
                                    </p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">{{ $package->category->name }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-blue-50 text-blue-700 border-blue-200">
                                        {{ $package->installer_type->label() }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ $package->architecture->value }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600 text-sm">
                                    @if ($package->installer_type->requiresBinary())
                                        {{ $package->latestVersion?->version ?? 'no version yet' }}
                                        <span class="text-xs text-gray-400">({{ $package->versions_count }})</span>
                                    @else
                                        <span class="text-xs text-gray-400">resolved at install time</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span @class([
                                        'text-xs font-semibold rounded-full px-2 py-0.5 border',
                                        'bg-green-50 text-green-700 border-green-200' => $package->is_active,
                                        'bg-gray-100 text-gray-600 border-gray-200' => ! $package->is_active,
                                    ])>{{ $package->is_active ? 'active' : 'inactive' }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-2">
                                    @if ($package->trashed())
                                        @can('restore', $package)
                                            <button wire:click="restore({{ $package->id }})"
                                                    class="font-semibold text-indigo-600 hover:underline">Restore</button>
                                        @endcan
                                    @else
                                        @can('update', $package)
                                            <button wire:click="toggleActive({{ $package->id }})"
                                                    class="font-semibold text-amber-600 hover:underline">
                                                {{ $package->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                            <a href="{{ route('packages.edit', $package) }}"
                                               class="font-semibold text-indigo-600 hover:underline">Edit</a>
                                        @endcan
                                        @can('delete', $package)
                                            <button wire:click="delete({{ $package->id }})"
                                                    wire:confirm="Delete package “{{ $package->name }}”? It can be restored later."
                                                    class="font-semibold text-red-600 hover:underline">Delete</button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No packages found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $packages->links() }}
        </div>
    </div>
</div>
