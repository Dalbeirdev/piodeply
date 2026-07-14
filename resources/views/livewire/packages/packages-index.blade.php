<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-slate-900 leading-tight">{{ __('Packages') }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">Approved software repository — {{ $packages->total() }} packages</p>
            </div>
            @can('create', \App\Models\Package::class)
                <a href="{{ route('packages.create') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 rounded-lg font-semibold text-sm text-white shadow-sm hover:bg-teal-800 transition">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    New Package
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="pd-card p-3 text-sm text-emerald-700 !bg-emerald-50 border-emerald-200" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Search name, vendor, package id…" aria-label="Search packages"
                           class="pd-input pl-9 w-80">
                </div>
                <select wire:model.live="categoryId" aria-label="Filter by category" class="pd-select">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="installerType" aria-label="Filter by installer type" class="pd-select">
                    <option value="">All types</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="activeOnly" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Active only
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Show deleted
                </label>
            </div>

            <div class="pd-card">
                <table class="min-w-full divide-y divide-slate-100">
                    <thead>
                        <tr>
                            <th class="pd-th">Package</th>
                            <th class="pd-th">Category</th>
                            <th class="pd-th">Type</th>
                            <th class="pd-th">Latest version</th>
                            <th class="pd-th">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($packages as $package)
                            <tr @class(['opacity-50' => $package->trashed()])>
                                <td class="px-6 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <span class="pd-tile" aria-hidden="true">{{ strtoupper(mb_substr($package->name, 0, 1)) }}</span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <a href="{{ route('packages.show', $package) }}" class="pd-link text-[15px]">{{ $package->name }}</a>
                                                @if ($package->trashed())
                                                    <span class="pd-badge pd-badge-red">deleted</span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-slate-400 truncate">
                                                {{ $package->vendor }}
                                                @if ($package->winget_id)
                                                    <span class="text-slate-300">·</span>
                                                    <code class="font-mono text-slate-500">{{ $package->winget_id }}</code>
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-sm text-slate-600">{{ $package->category->name }}</td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    <span class="pd-badge pd-badge-sky">{{ $package->installer_type->label() }}</span>
                                    <span class="ml-1 text-[11px] font-mono text-slate-400">{{ $package->architecture->value }}</span>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-sm">
                                    @if ($package->installer_type->requiresBinary())
                                        <span class="text-slate-700 font-medium">{{ $package->latestVersion?->version ?? '—' }}</span>
                                        <span class="text-xs text-slate-400">({{ $package->versions_count }})</span>
                                    @else
                                        <span class="text-xs text-slate-400 italic">resolved at install time</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    @if ($package->is_active)
                                        <span class="pd-badge pd-badge-green"><span class="pd-dot"></span>active</span>
                                    @else
                                        <span class="pd-badge pd-badge-slate"><span class="pd-dot"></span>inactive</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-right space-x-3">
                                    @if ($package->trashed())
                                        @can('restore', $package)
                                            <button wire:click="restore({{ $package->id }})" class="pd-action">Restore</button>
                                        @endcan
                                    @else
                                        @can('update', $package)
                                            <button wire:click="toggleActive({{ $package->id }})" class="pd-action-amber">
                                                {{ $package->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                            <a href="{{ route('packages.edit', $package) }}" class="pd-action">Edit</a>
                                        @endcan
                                        @can('delete', $package)
                                            <button wire:click="delete({{ $package->id }})"
                                                    wire:confirm="Delete package “{{ $package->name }}”? It can be restored later."
                                                    class="pd-action-danger">Delete</button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-12 text-center text-slate-400">No packages found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $packages->links() }}
        </div>
    </div>
</div>
