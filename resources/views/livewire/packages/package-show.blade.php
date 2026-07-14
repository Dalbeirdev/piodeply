<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="pd-tile">
                    <x-category-icon :name="$package->category->name" />
                </span>
                <h2 class="font-semibold text-xl text-slate-900 leading-tight">
                    {{ $package->name }}
                    <span class="ml-2 align-middle pd-badge pd-badge-sky">{{ $package->installer_type->label() }}</span>
                </h2>
            </div>
            @can('update', $package)
                <a href="{{ route('packages.edit', $package) }}"
                   class="text-sm pd-action">Edit package</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="pd-card p-6">
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-slate-500">Category</dt><dd class="text-slate-900">{{ $package->category->name }}</dd></div>
                    <div><dt class="text-slate-500">Vendor</dt><dd class="text-slate-900">{{ $package->vendor ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Architecture</dt><dd class="text-slate-900">{{ $package->architecture->value }}</dd></div>
                    <div><dt class="text-slate-500">License</dt><dd class="text-slate-900">{{ $package->license ?? '—' }}</dd></div>
                    @if ($package->winget_id)
                        <div class="col-span-2"><dt class="text-slate-500">winget ID</dt>
                            <dd class="font-mono text-slate-900 select-all">{{ $package->winget_id }}</dd></div>
                    @endif
                    @if ($package->choco_id)
                        <div class="col-span-2"><dt class="text-slate-500">Chocolatey ID</dt>
                            <dd class="font-mono text-slate-900 select-all">{{ $package->choco_id }}</dd></div>
                    @endif
                    @if ($package->homepage)
                        <div class="col-span-2"><dt class="text-slate-500">Homepage</dt>
                            <dd><a href="{{ $package->homepage }}" target="_blank" rel="noopener" class="text-teal-600 hover:underline">{{ $package->homepage }}</a></dd></div>
                    @endif
                    @if ($package->description)
                        <div class="col-span-full"><dt class="text-slate-500">Description</dt>
                            <dd class="text-slate-900">{{ $package->description }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="pd-card p-6 space-y-4">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Versions</h3>

                @if ($package->installer_type->requiresBinary())
                    @can('update', $package)
                        <form wire:submit="addVersion" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-start border rounded-md p-3 bg-slate-50">
                            <div>
                                <x-input type="text" class="block w-full text-sm" placeholder="Version *" aria-label="Version" wire:model="version" />
                                <x-input-error for="version" class="mt-1" />
                            </div>
                            <div class="md:col-span-2">
                                <x-input type="url" class="block w-full text-sm font-mono" placeholder="https://installer url *" aria-label="Installer URL" wire:model="installer_url" />
                                <x-input-error for="installer_url" class="mt-1" />
                            </div>
                            <div class="md:col-span-2">
                                <x-input type="text" class="block w-full text-sm font-mono" placeholder="SHA-256 *" aria-label="SHA-256" wire:model="sha256" />
                                <x-input-error for="sha256" class="mt-1" />
                            </div>
                            <div>
                                <x-input type="text" class="block w-full text-sm font-mono" placeholder="Silent args" aria-label="Silent arguments" wire:model="silent_args" />
                            </div>
                            <div class="md:col-span-2">
                                <x-input type="text" class="block w-full text-sm font-mono" placeholder="Uninstall args" aria-label="Uninstall arguments" wire:model="uninstall_args" />
                            </div>
                            <div>
                                <x-input type="date" class="block w-full text-sm" aria-label="Release date" wire:model="release_date" />
                            </div>
                            <div class="md:col-span-3 flex justify-end">
                                <x-button type="submit">Add version</x-button>
                            </div>
                        </form>
                    @endcan

                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="pd-th">Version</th>
                                <th class="pd-th">Installer URL</th>
                                <th class="pd-th">SHA-256</th>
                                <th class="pd-th">Silent args</th>
                                <th class="pd-th">Released</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($package->versions as $version)
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap font-medium">
                                        {{ $version->version }}
                                        @if ($version->is_latest)
                                            <span class="ml-1 text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200">latest</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs max-w-xs truncate" title="{{ $version->installer_url }}">{{ $version->installer_url }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ $version->sha256 ? substr($version->sha256, 0, 12) . '…' : '—' }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ $version->silent_args ?? '—' }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap text-slate-500">{{ $version->release_date?->toDateString() ?? '—' }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap text-right space-x-2">
                                        @can('update', $package)
                                            @unless ($version->is_latest)
                                                <button wire:click="markLatest({{ $version->id }})"
                                                        class="text-xs pd-action">Mark latest</button>
                                            @endunless
                                            <button wire:click="removeVersion({{ $version->id }})"
                                                    wire:confirm="Remove version {{ $version->version }}?"
                                                    class="text-xs pd-action-danger">Remove</button>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">No versions yet — add the first one above.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                @else
                    <p class="text-sm text-slate-600">
                        {{ $package->installer_type->label() }} packages resolve their latest version from the
                        package-manager repository at install time — no version rows are needed here.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
