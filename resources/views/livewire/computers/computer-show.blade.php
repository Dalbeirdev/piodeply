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

            {{-- Browser policies --}}
            @if ($browserPolicyRows->isNotEmpty())
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Browser policies</h3>
                    <div class="space-y-3">
                        @foreach ($browserPolicyRows as $row)
                            <div class="flex flex-wrap items-center gap-3 text-sm {{ $row['excluded'] ? 'opacity-50' : '' }}">
                                <a href="{{ route('browser-policies.show', $row['policy']) }}" class="pd-link font-medium w-64 truncate">
                                    {{ $row['policy']->name }}
                                </a>
                                @if ($row['excluded'])
                                    <span class="text-xs text-slate-400">Excluded from this machine</span>
                                @elseif ($row['results']->isEmpty())
                                    <span class="text-xs text-blue-600">Awaiting agent</span>
                                @else
                                    @foreach ($row['results'] as $browser => $result)
                                        <span class="text-xs" title="{{ $result->detail }}">
                                            <span class="text-slate-500">{{ \App\Enums\Browser::from($browser)->label() }}:</span>
                                            <span @class([
                                                'font-semibold',
                                                'text-green-600' => $result->status === 'compliant',
                                                'text-red-600' => in_array($result->status, ['non_compliant', 'error'], true),
                                                'text-blue-600' => $result->status === 'pending_restart',
                                                'text-amber-600' => $result->status === 'unsupported',
                                                'text-slate-400' => $result->status === 'not_installed',
                                            ])>{{ str_replace('_', ' ', $result->status) }}</span>
                                        </span>
                                    @endforeach
                                    <span class="text-xs text-slate-400">· checked {{ $row['results']->max('reported_at')?->diffForHumans() }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Installed software --}}
            <div class="pd-card">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-3">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Installed software
                        <span class="ml-1 text-slate-400 font-normal normal-case">
                            ({{ $softwareDeployed }} by PioDeploy · {{ $softwareManaged }} managed · {{ $softwareTotal }} detected)
                        </span>
                    </h3>
                    <div class="flex items-center gap-4">
                        <select wire:model.live="softwareFilter" aria-label="Filter software"
                                class="border-slate-300 rounded-md shadow-sm text-sm py-1.5">
                            <option value="managed">In catalogue</option>
                            <option value="deployed">Deployed by PioDeploy</option>
                            <option value="all">All software</option>
                        </select>
                        <input type="search" wire:model.live.debounce.300ms="softwareSearch"
                               placeholder="Search software…" aria-label="Search installed software"
                               class="pd-input w-64 py-1.5">
                    </div>
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
                                <th class="pd-th">Installed by</th>
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
                                    <td class="px-6 py-2.5 whitespace-nowrap">
                                        @if ($deployedNames->contains($item->name))
                                            <span class="pd-badge pd-badge-sky"
                                                  title="A PioDeploy job installed this on {{ $computer->hostname }}">PioDeploy</span>
                                        @else
                                            {{-- Absence of a job is not proof it predates us, so say
                                                 nothing rather than claim "pre-existing". --}}
                                            <span class="text-xs text-slate-300" title="No PioDeploy install job for this package on this machine">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">
                                    @if ($softwareTotal === 0)
                                        No software inventory reported yet — it arrives with the agent's next report.
                                    @elseif ($softwareSearch !== '')
                                        No software matches your search.
                                    @elseif ($softwareFilter === 'deployed')
                                        No PioDeploy installs recorded on this machine yet.
                                    @elseif ($softwareFilter === 'managed')
                                        No catalogue software detected out of {{ $softwareTotal }} entries — switch to
                                        <b>All software</b> to see them.
                                        @if ($computer->agent_version && version_compare($computer->agent_version, '1.3.1', '<'))
                                            <span class="block mt-2 text-amber-600">
                                                <b>This agent is {{ $computer->agent_version }}.</b>
                                                <span>Agents before 1.3.1 cannot scan winget as SYSTEM, so nothing matches the catalogue — upgrade the agent.</span>
                                            </span>
                                        @endif
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

            {{-- Why software is, or is not, where it should be. Answers the
                 question the job list cannot: nothing happened, and why. --}}
            <div class="pd-card">
                <div class="flex items-center justify-between px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Software status
                        <span class="ml-1 text-slate-400 font-normal normal-case">— what each policy wants here, and why</span>
                    </h3>
                    <a href="{{ route('policies.index') }}" class="text-sm pd-action">Policies →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="pd-th">Package</th>
                                <th class="pd-th">Policy wants</th>
                                <th class="pd-th">Installed</th>
                                <th class="pd-th">State</th>
                                <th class="pd-th">Why</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($policyExplanations as $row)
                                @php
                                    $badge = match ($row['status']) {
                                        'compliant'     => 'pd-badge-green',
                                        'non_compliant' => 'pd-badge-red',
                                        'failed'        => 'pd-badge-red',
                                        'pending'       => 'pd-badge-sky',
                                        'scheduled'     => 'pd-badge-amber',
                                        default         => 'pd-badge-slate',
                                    };
                                @endphp
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <a href="{{ route('packages.show', $row['policy']->package) }}"
                                           class="pd-link text-sm">{{ $row['policy']->package->name }}</a>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">
                                        {{ $row['policy']->action->label() }}
                                        @if ($row['policy']->mode !== \App\Enums\PolicyMode::Enforce)
                                            <span class="ml-1 text-xs text-slate-400">({{ $row['policy']->mode->label() }})</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500 font-mono text-[13px]">
                                        {{ $row['installed_version'] ?? '—' }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="pd-badge {{ $badge }}"><span class="pd-dot"></span>{{ str($row['status'])->replace('_', ' ')->ucfirst() }}</span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-slate-600">{{ $row['reason'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">
                                    No software policies target this machine's project, so nothing is being enforced here.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
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
                                        @if ($label = $job->versionLabel())
                                            <span class="block text-xs text-slate-400 font-mono">{{ $label }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">
                                        {{ $job->action->label() }}
                                        @if ($job->repeat_count > 1)
                                            <span class="ml-1 text-xs text-slate-400"
                                                  title="Requested {{ $job->repeat_count }} times — showing the latest">
                                                ×{{ $job->repeat_count }}
                                            </span>
                                        @endif
                                    </td>
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

            {{-- Deployment log: every attempt with the reason it ended that
                 way, and the agent's own output behind it. --}}
            <div class="pd-card">
                <div class="px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Deployment log
                        <span class="ml-1 text-slate-400 font-normal normal-case">— every attempt and why it ended that way</span>
                    </h3>
                </div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($jobLog as $job)
                        @php
                            $dot = match ($job->status) {
                                \App\Enums\JobStatus::Succeeded => 'bg-green-500',
                                \App\Enums\JobStatus::Failed    => 'bg-red-500',
                                \App\Enums\JobStatus::Running   => 'bg-sky-500',
                                \App\Enums\JobStatus::Blocked   => 'bg-amber-500',
                                default                         => 'bg-slate-300',
                            };
                        @endphp
                        <li class="px-6 py-3" x-data="{ open: false }">
                            <div class="flex items-start gap-3">
                                <span class="mt-1.5 h-1.5 w-1.5 rounded-full shrink-0 {{ $dot }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-700">
                                        <span class="font-medium">{{ $job->action->label() }}</span>
                                        {{ $job->package->name }}
                                        @if ($label = $job->versionLabel())
                                            <span class="text-xs text-slate-400 font-mono">({{ $label }})</span>
                                        @endif
                                    </p>
                                    <p class="text-sm text-slate-500">{{ $job->reasonLabel() }}</p>

                                    @if ($job->output_log || $job->exit_code !== null)
                                        <button type="button" @click="open = ! open"
                                                class="mt-1 text-xs pd-link" :aria-expanded="open ? 'true' : 'false'">
                                            <span x-text="open ? 'Hide agent output' : 'Show agent output'">Show agent output</span>
                                            @if ($job->exit_code !== null)
                                                <span class="text-slate-400 font-mono">· exit {{ $job->exit_code }}</span>
                                            @endif
                                        </button>
                                        <pre x-show="open" x-cloak x-collapse
                                             class="mt-2 bg-slate-900 text-slate-100 rounded-lg p-3 overflow-x-auto text-xs whitespace-pre-wrap break-words max-h-72">{{ $job->output_log ?: '(the agent reported no output)' }}</pre>
                                    @endif
                                </div>
                                <time class="text-xs text-slate-400 whitespace-nowrap shrink-0"
                                      datetime="{{ ($job->finished_at ?? $job->created_at)->toIso8601String() }}">
                                    {{ ($job->finished_at ?? $job->created_at)->diffForHumans() }}
                                </time>
                            </div>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-center text-slate-400">
                            Nothing has been deployed to this machine yet, so there is nothing to explain.
                        </li>
                    @endforelse
                </ul>
                @if ($jobLog->count() >= 30)
                    <p class="px-6 py-2 text-xs text-slate-400 border-t border-slate-100">
                        Showing the 30 most recent attempts —
                        <a href="{{ route('deployments.index') }}" class="pd-link">Deployments</a> has the full history.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
