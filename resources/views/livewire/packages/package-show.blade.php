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
                    @if ($package->is_active)
                        <span class="ml-1 align-middle pd-badge pd-badge-green"><span class="pd-dot"></span>active</span>
                    @else
                        <span class="ml-1 align-middle pd-badge pd-badge-slate"><span class="pd-dot"></span>inactive</span>
                    @endif
                </h2>
            </div>
            @can('update', $package)
                <a href="{{ route('packages.edit', $package) }}" class="text-sm pd-action">Edit package</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">
            @if (session('status'))
                <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Fleet stats --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-teal-700 leading-tight">{{ $stats['installed_on'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Installed on</p>
                    <p class="text-xs text-slate-400">{{ Str::plural('computer', $stats['installed_on']) }} (succeeded)</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-sky-600 leading-tight">{{ $stats['in_flight'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">In flight</p>
                    <p class="text-xs text-slate-400">pending / running / blocked</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold {{ $stats['failed'] > 0 ? 'text-red-600' : 'text-slate-300' }} leading-tight">{{ $stats['failed'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Failed</p>
                    <p class="text-xs text-slate-400">out of retries</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-slate-700 leading-tight">
                        {{ $stats['last_deploy'] ? \Illuminate\Support\Carbon::parse($stats['last_deploy'])->diffForHumans(short: true) : '—' }}
                    </p>
                    <p class="text-sm font-semibold text-slate-700">Last deployed</p>
                    <p class="text-xs text-slate-400">{{ $stats['last_deploy'] ? \Illuminate\Support\Carbon::parse($stats['last_deploy'])->format('Y-m-d H:i') : 'never' }}</p>
                </div>
            </div>

            {{-- Details --}}
            <div class="pd-card p-6">
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-slate-400">Category</dt><dd class="text-slate-900 font-medium">{{ $package->category->name }}</dd></div>
                    <div><dt class="text-slate-400">Vendor</dt><dd class="text-slate-900 font-medium">{{ $package->vendor ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400">Architecture</dt><dd class="text-slate-900 font-medium">{{ $package->architecture->value }}</dd></div>
                    <div><dt class="text-slate-400">License</dt><dd class="text-slate-900 font-medium">{{ $package->license ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-slate-400">Latest version</dt>
                        <dd class="text-slate-900 font-medium">
                            @if ($package->installer_type->requiresBinary())
                                {{ $package->latestVersion?->version ?? 'none yet' }}
                            @else
                                auto — winget resolves at install
                            @endif
                        </dd>
                    </div>
                    @if ($package->winget_id)
                        <div class="col-span-2"><dt class="text-slate-400">winget ID</dt>
                            <dd class="font-mono text-slate-900 select-all">{{ $package->winget_id }}</dd></div>
                    @endif
                    @if ($package->choco_id)
                        <div class="col-span-2"><dt class="text-slate-400">Chocolatey ID</dt>
                            <dd class="font-mono text-slate-900 select-all">{{ $package->choco_id }}</dd></div>
                    @endif
                    @if ($package->homepage)
                        <div class="col-span-2"><dt class="text-slate-400">Homepage</dt>
                            <dd><a href="{{ $package->homepage }}" target="_blank" rel="noopener" class="pd-link font-normal">{{ $package->homepage }}</a></dd></div>
                    @endif
                    <div><dt class="text-slate-400">Added</dt><dd class="text-slate-700">{{ $package->created_at->format('Y-m-d') }}</dd></div>
                    <div><dt class="text-slate-400">Updated</dt><dd class="text-slate-700">{{ $package->updated_at->diffForHumans() }}</dd></div>
                    @if ($package->description)
                        <div class="col-span-full"><dt class="text-slate-400">Description</dt>
                            <dd class="text-slate-900">{{ $package->description }}</dd></div>
                    @endif
                </dl>
            </div>

            {{-- Quick deploy --}}
            @can('create', \App\Models\DeploymentJob::class)
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Deploy this package</h3>
                    <form wire:submit="deploy" class="grid grid-cols-1 md:grid-cols-4 gap-2 items-start">
                        <div class="md:col-span-2">
                            <select wire:model="deploy_computer_id" aria-label="Computer" class="pd-select w-full">
                                <option value="">— select computer —</option>
                                @foreach ($computers as $computer)
                                    <option value="{{ $computer->id }}">{{ $computer->hostname }}</option>
                                @endforeach
                            </select>
                            <x-input-error for="deploy_computer_id" class="mt-1" />
                        </div>
                        <select wire:model="deploy_action" aria-label="Action" class="pd-select w-full">
                            @foreach ($actions as $a)
                                <option value="{{ $a->value }}">{{ $a->label() }}</option>
                            @endforeach
                        </select>
                        <div class="flex gap-2">
                            <select wire:model="deploy_priority" aria-label="Priority" class="pd-select w-full" title="1 = highest">
                                @foreach (range(1, 10) as $p)
                                    <option value="{{ $p }}">P{{ $p }}</option>
                                @endforeach
                            </select>
                            <x-button type="submit">Queue</x-button>
                        </div>
                    </form>
                </div>
            @endcan

            {{-- Recent deployments --}}
            <div class="pd-card">
                <div class="flex items-center justify-between px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Recent deployments</h3>
                    <a href="{{ route('deployments.index') }}" class="text-sm pd-action">View all →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="pd-th">Computer</th>
                                <th class="pd-th">Action</th>
                                <th class="pd-th">Status</th>
                                <th class="pd-th">Attempts</th>
                                <th class="pd-th">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($recentJobs as $job)
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <a href="{{ route('computers.show', $job->computer) }}" class="pd-link text-sm">{{ $job->computer->hostname }}</a>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">{{ $job->action->label() }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @php
                                            $badge = match ($job->status) {
                                                \App\Enums\JobStatus::Succeeded => 'pd-badge-green',
                                                \App\Enums\JobStatus::Failed => 'pd-badge-red',
                                                \App\Enums\JobStatus::Running => 'pd-badge-sky',
                                                \App\Enums\JobStatus::Blocked => 'pd-badge-amber',
                                                default => 'pd-badge-slate',
                                            };
                                        @endphp
                                        <span class="pd-badge {{ $badge }}"><span class="pd-dot"></span>{{ $job->status->label() }}</span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $job->attempts }}/{{ $job->max_attempts }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $job->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">Never deployed. Queue it above to get started.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Versions --}}
            <div class="pd-card p-6 space-y-4">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Versions</h3>

                @if ($package->installer_type->requiresBinary())
                    @can('update', $package)
                        <form wire:submit="addVersion" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-start border rounded-xl p-3 bg-slate-50">
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

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
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
                                @forelse ($package->versions as $packageVersion)
                                    <tr>
                                        <td class="px-4 py-2 whitespace-nowrap font-medium">
                                            {{ $packageVersion->version }}
                                            @if ($packageVersion->is_latest)
                                                <span class="ml-1 pd-badge pd-badge-green">latest</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 font-mono text-xs max-w-xs truncate" title="{{ $packageVersion->installer_url }}">{{ $packageVersion->installer_url }}</td>
                                        <td class="px-4 py-2 font-mono text-xs">{{ $packageVersion->sha256 ? substr($packageVersion->sha256, 0, 12) . '…' : '—' }}</td>
                                        <td class="px-4 py-2 font-mono text-xs">{{ $packageVersion->silent_args ?? '—' }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-slate-500">{{ $packageVersion->release_date?->toDateString() ?? '—' }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-right space-x-2">
                                            @can('update', $package)
                                                @unless ($packageVersion->is_latest)
                                                    <button wire:click="markLatest({{ $packageVersion->id }})"
                                                            class="text-xs pd-action">Mark latest</button>
                                                @endunless
                                                <x-icon-button icon="delete" variant="danger" label="Remove version"
                                                               wire:click="removeVersion({{ $packageVersion->id }})"
                                                               wire:confirm="Remove version {{ $packageVersion->version }}?" />
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No versions yet — add the first one above.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-slate-500">
                        {{ $package->installer_type->label() }} packages resolve their latest version from the
                        package-manager repository at install time — the fleet always gets the current release
                        without maintaining version rows here.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
