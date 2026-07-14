<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">
                {{ $computer->hostname }}
                @if ($computer->isOnline())
                    <span class="ml-2 align-middle pd-badge pd-badge-green"><span class="pd-dot"></span>Online</span>
                @else
                    <span class="ml-2 align-middle pd-badge pd-badge-slate"><span class="pd-dot"></span>Offline</span>
                @endif
            </h2>
            @can('update', $computer)
                <a href="{{ route('computers.edit', $computer) }}" class="text-sm pd-action">Reassign project</a>
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

            {{-- Health summary --}}
            @if (count($health) === 0)
                <div class="pd-card p-4 flex items-center gap-3">
                    <span class="h-8 w-8 rounded-full bg-emerald-50 border border-emerald-200 grid place-content-center">
                        <svg class="h-4 w-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <p class="text-sm font-semibold text-emerald-700">No issues detected on this machine.</p>
                </div>
            @else
                <div class="pd-card p-4 space-y-2" role="alert">
                    <p class="text-sm font-semibold text-slate-800">Attention required</p>
                    @foreach ($health as $check)
                        <div class="flex items-start gap-2 text-sm {{ $check['level'] === 'warn' ? 'text-amber-700' : 'text-slate-500' }}">
                            @if ($check['level'] === 'warn')
                                <svg class="h-4 w-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                            @else
                                <svg class="h-4 w-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                            @endif
                            <span>{{ $check['message'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Deployment stats --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-teal-700 leading-tight">{{ $stats['succeeded'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Deployments</p>
                    <p class="text-xs text-slate-400">succeeded</p>
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
                    <p class="text-sm font-semibold text-slate-700">Last deployment</p>
                    <p class="text-xs text-slate-400">{{ $stats['last_deploy'] ? \Illuminate\Support\Carbon::parse($stats['last_deploy'])->format('Y-m-d H:i') : 'never' }}</p>
                </div>
            </div>

            {{-- Deploy widget --}}
            @can('create', \App\Models\DeploymentJob::class)
                @livewire('deployments.deploy-to-computer', ['computer' => $computer], key('deploy-'.$computer->id))
            @endcan

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                @php
                    $sections = [
                        'Assignment' => [
                            'Client' => $computer->project->client->company_name,
                            'Project' => $computer->project->name,
                            'Enrolled' => $computer->created_at->format('Y-m-d') . ' (' . $computer->created_at->diffForHumans() . ')',
                            'Agent version' => $computer->agent_version,
                            'Agent UUID' => $computer->agent_uuid,
                            'Last seen' => $computer->last_seen_at ? $computer->last_seen_at->format('Y-m-d H:i:s') . ' (' . $computer->last_seen_at->diffForHumans() . ')' : 'never',
                            'Inventory updated' => $computer->updated_at->diffForHumans(),
                        ],
                        'System' => [
                            'Manufacturer' => $computer->manufacturer,
                            'Model' => $computer->model,
                            'Serial number' => $computer->serial_number,
                            'OS' => $computer->os_name,
                            'OS version' => $computer->os_version,
                            'Windows build' => $computer->windows_build,
                        ],
                        'Network' => [
                            'Private IP' => $computer->private_ip,
                            'Public IP' => $computer->public_ip,
                            'MAC address' => $computer->mac_address,
                        ],
                    ];
                @endphp

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Assignment</h3>
                    <dl class="space-y-2">
                        @foreach ($sections['Assignment'] as $label => $value)
                            <div class="flex justify-between gap-4 text-sm">
                                <dt class="text-slate-400 shrink-0">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right break-all">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">System</h3>
                    <dl class="space-y-2">
                        @foreach ($sections['System'] as $label => $value)
                            <div class="flex justify-between gap-4 text-sm">
                                <dt class="text-slate-400 shrink-0">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right break-all">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Hardware</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-400">CPU</dt>
                            <dd class="text-slate-900 text-right">{{ $computer->cpu ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-400">RAM</dt>
                            <dd class="text-slate-900 text-right">{{ $computer->ramForHumans() ?? '—' }}</dd>
                        </div>
                        <div>
                            <div class="flex justify-between gap-4 mb-1.5">
                                <dt class="text-slate-400">System disk</dt>
                                <dd class="text-slate-900 text-right">{{ $computer->diskForHumans() ?? '—' }}</dd>
                            </div>
                            @if ($diskUsedPercent !== null)
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden"
                                     role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $diskUsedPercent }}"
                                     aria-label="Disk usage {{ $diskUsedPercent }} percent">
                                    <div class="h-full rounded-full {{ $diskUsedPercent >= 90 ? 'bg-red-500' : ($diskUsedPercent >= 75 ? 'bg-amber-400' : 'bg-teal-500') }}"
                                         style="width: {{ $diskUsedPercent }}%"></div>
                                </div>
                                <p class="text-xs text-slate-400 mt-1">{{ $diskUsedPercent }}% used</p>
                            @endif
                        </div>
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Network</h3>
                    <dl class="space-y-2">
                        @foreach ($sections['Network'] as $label => $value)
                            <div class="flex justify-between gap-4 text-sm">
                                <dt class="text-slate-400 shrink-0">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right font-mono text-[13px]">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Security posture</h3>
                    <dl class="space-y-2">
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-slate-400">Secure Boot</dt>
                            <dd>
                                @if ($computer->secure_boot === null) <span class="text-slate-400">unknown</span>
                                @elseif ($computer->secure_boot) <span class="text-emerald-700 font-semibold">Enabled</span>
                                @else <span class="text-red-600 font-semibold">Disabled</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-slate-400">TPM</dt>
                            <dd>
                                @if ($computer->tpm_enabled === null) <span class="text-slate-400">unknown</span>
                                @elseif ($computer->tpm_enabled) <span class="text-emerald-700 font-semibold">Enabled{{ $computer->tpm_version ? ' (v' . $computer->tpm_version . ')' : '' }}</span>
                                @else <span class="text-red-600 font-semibold">Disabled</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- Recent activity --}}
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Recent activity</h3>
                    @if ($recentActivity->isEmpty())
                        <p class="text-sm text-slate-400">No changes recorded.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($recentActivity as $activity)
                                <li class="text-sm flex justify-between gap-3">
                                    <span class="text-slate-700">
                                        {{ ucfirst($activity->description) }}
                                        @if ($activity->causer)
                                            <span class="text-slate-400">by {{ $activity->causer->name }}</span>
                                        @endif
                                    </span>
                                    <span class="text-slate-400 whitespace-nowrap">{{ $activity->created_at->diffForHumans(short: true) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Installed software --}}
            <div class="pd-card">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-3">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Installed software
                        <span class="ml-1 text-slate-400 font-normal normal-case">({{ $softwareTotal }} detected)</span>
                    </h3>
                    <input type="search" wire:model.live.debounce.300ms="softwareSearch"
                           placeholder="Search software…" aria-label="Search installed software"
                           class="pd-input w-64 py-1.5">
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="pd-th">Name</th>
                                <th class="pd-th">Version</th>
                                <th class="pd-th">Publisher</th>
                                <th class="pd-th">Source</th>
                                <th class="pd-th">Catalogue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($softwareItems as $item)
                                <tr>
                                    <td class="px-6 py-2.5 text-sm text-slate-800 {{ $item->source === 'winget' ? 'font-mono text-[13px]' : '' }}">{{ $item->name }}</td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm text-slate-500 font-mono text-[13px]">{{ $item->version ?? '—' }}</td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm text-slate-500 max-w-[16rem] truncate">{{ $item->publisher ?? '—' }}</td>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        <span class="pd-badge {{ $item->source === 'winget' ? 'pd-badge-sky' : ($item->source === 'choco' ? 'pd-badge-amber' : 'pd-badge-slate') }}">{{ $item->source }}</span>
                                    </td>
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        @if ($item->source === 'winget' && $managedPackages->has($item->name))
                                            <a href="{{ route('packages.show', $managedPackages[$item->name]) }}"
                                               class="pd-badge pd-badge-teal hover:bg-teal-100">managed</a>
                                        @else
                                            <span class="text-xs text-slate-300">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">
                                    @if ($softwareTotal === 0)
                                        No software inventory reported yet — it arrives with the agent's next report.
                                    @else
                                        No software matches your search.
                                    @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($softwareItems->count() === 150)
                    <p class="px-6 py-2 text-xs text-slate-400 border-t border-slate-100">Showing first 150 matches — refine the search to narrow down.</p>
                @endif
            </div>

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
                                <th class="pd-th">Package</th>
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
                                        <a href="{{ route('packages.show', $job->package) }}" class="pd-link text-sm">{{ $job->package->name }}</a>
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
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">No deployments yet — queue one above.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
